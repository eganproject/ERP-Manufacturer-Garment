<?php

namespace App\Http\Controllers\Payroll;

use App\Enums\PaymentMethod;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Services\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Kasbon karyawan (dipotong dari upah borongan saat penggajian).
 */
class EmployeeAdvanceController extends Controller
{
    public function __construct(private PayrollService $service) {}

    public function store(Request $request, Employee $employee)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'notes' => ['nullable', 'string', 'max:255'],
        ], [], ['amount' => 'jumlah kasbon', 'payment_method' => 'cara bayar']);

        $advance = $this->service->recordAdvance($data + ['employee_id' => $employee->id]);

        return back()->with('success', "Kasbon {$advance->number} sebesar ".rupiah($advance->amount).' dicatat.');
    }

    public function cancel(EmployeeAdvance $advance)
    {
        $this->service->cancelAdvance($advance);

        return back()->with('success', "Kasbon {$advance->number} dibatalkan.");
    }
}
