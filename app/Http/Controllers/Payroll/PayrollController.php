<?php

namespace App\Http\Controllers\Payroll;

use App\Enums\PaymentMethod;
use App\Enums\PayrollStatus;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Payroll;
use App\Models\PayrollLine;
use App\Models\PieceworkEntry;
use App\Services\PayrollService;
use App\Support\DocumentPdf;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Penggajian borongan per periode + slip upah.
 */
class PayrollController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:payroll.view', only: ['index', 'show', 'pdf', 'slips', 'slip']),
            new Middleware('can:payroll.manage', only: ['create', 'store', 'update', 'recalculate']),
            new Middleware('can:payroll.pay', only: ['approve', 'pay']),
        ];
    }

    public function __construct(private PayrollService $service) {}

    public function index(Request $request)
    {
        $payrolls = Payroll::withCount('lines')
            ->when($request->q, fn ($q, $s) => $q->where('number', 'like', "%{$s}%"))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest('period_end')->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('payroll.payrolls.index', [
            'payrolls' => $payrolls,
            'statuses' => PayrollStatus::options(),
            'unpaid' => round((float) PieceworkEntry::whereNull('payroll_line_id')->sum('amount'), 2),
            'pending' => round((float) Payroll::where('status', PayrollStatus::Approved)->sum('total_net'), 2),
        ]);
    }

    public function create()
    {
        [$start, $end] = PayrollService::suggestedPeriod();

        $preview = PieceworkEntry::with('employee')
            ->whereNull('payroll_line_id')
            ->get()
            ->groupBy('employee_id');

        return view('payroll.payrolls.create', compact('start', 'end', 'preview'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['period_start' => 'awal periode', 'period_end' => 'akhir periode']);

        $payroll = $this->service->create($data);

        return redirect()->route('payrolls.show', $payroll)->with('success', "Penggajian {$payroll->number} disusun. Periksa bonus & potongan, lalu setujui sebelum dibayar.");
    }

    public function show(Payroll $payroll)
    {
        $payroll->load(['lines.employee', 'lines.entries.sheet.productionOrder', 'creator', 'approver', 'payer', 'activities.user']);

        return view('payroll.payrolls.show', [
            'payroll' => $payroll,
            'methods' => PaymentMethod::options(),
            'balances' => $payroll->isDraft()
                ? $payroll->lines->mapWithKeys(fn (PayrollLine $l) => [$l->id => $l->employee->advanceBalance($l->id)])
                : collect(),
        ]);
    }

    public function update(Request $request, Payroll $payroll)
    {
        $data = $request->validate([
            'lines' => ['required', 'array'],
            'lines.*.allowance' => ['nullable', 'numeric', 'min:0'],
            'lines.*.advance_deduction' => ['nullable', 'numeric', 'min:0'],
            'lines.*.other_deduction' => ['nullable', 'numeric', 'min:0'],
            'lines.*.notes' => ['nullable', 'string', 'max:255'],
        ], [], ['lines.*.allowance' => 'bonus', 'lines.*.advance_deduction' => 'potongan kasbon', 'lines.*.other_deduction' => 'potongan lain']);

        $this->service->updateLines($payroll, $data['lines']);

        return back()->with('success', 'Slip upah diperbarui.');
    }

    public function recalculate(Payroll $payroll)
    {
        $this->service->recalculate($payroll);

        return back()->with('success', 'Hasil kerja dihitung ulang.');
    }

    public function approve(Payroll $payroll)
    {
        $this->service->approve($payroll);

        return back()->with('success', 'Penggajian disetujui dan siap dibayar.');
    }

    public function pay(Request $request, Payroll $payroll)
    {
        $data = $request->validate([
            'payment_date' => ['required', 'date'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            'cash_account_id' => ['nullable', Account::cashRule()],
        ], [], ['payment_date' => 'tanggal bayar', 'payment_method' => 'cara bayar']);

        $this->service->pay($payroll, $data);

        return back()->with('success', 'Upah borongan ditandai dibayar dan dijurnal.');
    }

    public function cancel(Request $request, Payroll $payroll)
    {
        abort_unless($request->user()->can($payroll->status === PayrollStatus::Draft ? 'payroll.manage' : 'payroll.pay'), 403);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $this->service->cancel($payroll, $data['reason'] ?? null);

        return back()->with('success', 'Penggajian dibatalkan; hasil kerjanya bisa dimasukkan ke penggajian lain.');
    }

    /** Rekap penggajian (daftar terima upah dengan kolom tanda tangan). */
    public function pdf(Payroll $payroll)
    {
        $payroll->load(['lines.employee', 'creator', 'approver']);

        return DocumentPdf::stream('pdf.payroll', ['payroll' => $payroll], $payroll->number, 'landscape');
    }

    /** Semua slip upah dalam satu PDF (satu halaman per karyawan). */
    public function slips(Payroll $payroll)
    {
        $payroll->load(['lines.employee', 'lines.entries.sheet.productionOrder.product']);

        return DocumentPdf::stream('pdf.payroll-slips', ['payroll' => $payroll, 'lines' => $payroll->lines], 'Slip-'.$payroll->number);
    }

    public function slip(Payroll $payroll, PayrollLine $line)
    {
        abort_unless($line->payroll_id === $payroll->id, 404);
        $line->load(['employee', 'entries.sheet.productionOrder.product']);

        return DocumentPdf::stream('pdf.payroll-slips', ['payroll' => $payroll, 'lines' => collect([$line])], 'Slip-'.$payroll->number.'-'.$line->employee->code);
    }
}
