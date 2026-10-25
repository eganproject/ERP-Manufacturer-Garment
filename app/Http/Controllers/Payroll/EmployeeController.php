<?php

namespace App\Http\Controllers\Payroll;

use App\Enums\EmployeeWorkType;
use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Master karyawan produksi borongan.
 */
class EmployeeController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:payroll.view', only: ['index', 'show']),
            new Middleware('can:employees.manage', except: ['index', 'show']),
        ];
    }

    public function index(Request $request)
    {
        $employees = Employee::query()
            ->withSum(['entries as unpaid_amount' => fn ($q) => $q->whereNull('payroll_line_id')], 'amount')
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")
                ->orWhere('code', 'like', "%{$s}%")
                ->orWhere('phone', 'like', "%{$s}%")))
            ->when($request->type, fn ($q, $t) => $q->where('work_type', $t))
            ->when($request->status, fn ($q, $s) => $q->where('is_active', $s === 'active'))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('payroll.employees.index', ['employees' => $employees, 'types' => EmployeeWorkType::options()]);
    }

    public function create()
    {
        $last = Employee::where('code', 'like', 'KRY-%')->orderByDesc('code')->value('code');
        $next = 'KRY-'.str_pad((string) ((int) substr((string) $last, 4) + 1), 3, '0', STR_PAD_LEFT);

        return view('payroll.employees.form', ['employee' => new Employee(['code' => $next, 'is_active' => true, 'work_type' => EmployeeWorkType::Operation, 'join_date' => today()])]);
    }

    public function store(Request $request)
    {
        $employee = Employee::create($this->validated($request));
        ActivityLog::record('created', "Menambah karyawan {$employee->name}", $employee);

        return redirect()->route('employees.show', $employee)->with('success', 'Karyawan berhasil ditambahkan.');
    }

    public function show(Employee $employee)
    {
        $entries = $employee->entries()
            ->with(['sheet.productionOrder.product', 'payrollLine.payroll'])
            ->join('piecework_sheets', 'piecework_sheets.id', '=', 'piecework_entries.piecework_sheet_id')
            ->select('piecework_entries.*')
            ->orderByDesc('piecework_sheets.date')->orderByDesc('piecework_entries.id')
            ->paginate(20);

        return view('payroll.employees.show', [
            'employee' => $employee->load(['advances.creator', 'activities.user']),
            'entries' => $entries,
            'payrollLines' => $employee->payrollLines()->with('payroll')->latest('id')->limit(12)->get(),
            'unpaid' => $employee->unpaidAmount(),
            'advanceBalance' => $employee->advanceBalance(),
            'methods' => PaymentMethod::options(),
        ]);
    }

    public function edit(Employee $employee)
    {
        return view('payroll.employees.form', compact('employee'));
    }

    public function update(Request $request, Employee $employee)
    {
        $employee->update($this->validated($request, $employee));
        ActivityLog::record('updated', 'Mengubah data karyawan', $employee);

        return redirect()->route('employees.show', $employee)->with('success', 'Data karyawan diperbarui.');
    }

    public function destroy(Employee $employee)
    {
        if ($employee->entries()->exists() || $employee->advances()->exists()) {
            return back()->with('error', 'Karyawan sudah memiliki hasil kerja / kasbon, nonaktifkan saja.');
        }

        $employee->delete();
        ActivityLog::record('deleted', "Menghapus karyawan {$employee->name}");

        return redirect()->route('employees.index')->with('success', 'Karyawan dihapus.');
    }

    private function validated(Request $request, ?Employee $employee = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('employees')->ignore($employee)],
            'name' => ['required', 'string', 'max:255'],
            'work_type' => ['required', Rule::enum(EmployeeWorkType::class)],
            'nik' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string'],
            'bank_account' => ['nullable', 'string', 'max:255'],
            'join_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ], [], ['work_type' => 'pola kerja', 'bank_account' => 'rekening', 'join_date' => 'tanggal masuk']);

        return $data + ['is_active' => $request->boolean('is_active')];
    }
}
