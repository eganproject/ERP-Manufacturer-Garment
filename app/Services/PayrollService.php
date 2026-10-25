<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Enums\PayrollStatus;
use App\Enums\ReturnStatus;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\Payroll;
use App\Models\PayrollLine;
use App\Models\PieceworkEntry;
use App\Models\Setting;
use App\Services\Accounting\AutoJournal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penggajian borongan per periode (default mingguan, lihat Setting::payroll()).
 *
 * Alur: Draft (kumpulkan hasil kerja yang belum dibayar s.d. akhir periode, termasuk susulan periode
 * sebelumnya; atur bonus, potongan kasbon & potongan lain) → Disetujui → Dibayar (jurnal pelunasan
 * hutang upah). Pembatalan melepas hasil kerja agar bisa masuk penggajian lain dan membalik jurnal.
 */
class PayrollService
{
    public function __construct(private AutoJournal $journal) {}

    /**
     * Periode yang disarankan untuk penggajian berikutnya.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function suggestedPeriod(): array
    {
        $settings = Setting::payroll();
        $last = Payroll::where('status', '!=', PayrollStatus::Cancelled)->latest('period_end')->first();

        if ($settings['cycle'] === 'monthly') {
            $start = $last ? $last->period_end->copy()->addDay() : today()->startOfMonth();

            return [$start, $start->copy()->endOfMonth()->startOfDay()];
        }

        $days = $settings['cycle'] === 'biweekly' ? 14 : 7;

        if ($last) {
            $start = $last->period_end->copy()->addDay();
        } else {
            // Awal minggu berjalan sesuai hari awal periode (ISO: 1 = Senin)
            $start = today();
            while ($start->isoWeekday() !== $settings['week_start']) {
                $start->subDay();
            }
        }

        return [$start, $start->copy()->addDays($days - 1)];
    }

    /**
     * @param  array{period_start: string, period_end: string, notes?: string|null}  $data
     */
    public function create(array $data): Payroll
    {
        $start = Carbon::parse($data['period_start']);
        $end = Carbon::parse($data['period_end']);

        if ($end->lt($start)) {
            throw ValidationException::withMessages(['period_end' => 'Akhir periode harus setelah awal periode.']);
        }

        return DB::transaction(function () use ($data, $start, $end) {
            $payroll = Payroll::create([
                'number' => DocumentNumber::generate(Payroll::class, 'UPH', $end),
                'period_start' => $start,
                'period_end' => $end,
                'status' => PayrollStatus::Draft,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            if ($this->collect($payroll) === 0) {
                throw ValidationException::withMessages(['period_end' => 'Tidak ada hasil kerja borongan yang belum dibayar sampai '.tanggal($end).'.']);
            }

            ActivityLog::record('created', "Menyusun penggajian borongan {$payroll->number} periode {$payroll->periodLabel()}, total ".rupiah($payroll->total_net), $payroll);

            return $payroll;
        });
    }

    /** Ambil ulang hasil kerja (mis. ada input susulan) dengan mempertahankan bonus & potongan per karyawan. */
    public function recalculate(Payroll $payroll): void
    {
        $this->assertDraft($payroll);

        DB::transaction(function () use ($payroll) {
            $adjustments = $payroll->lines()->get()->keyBy('employee_id')
                ->map(fn (PayrollLine $l) => $l->only(['allowance', 'advance_deduction', 'other_deduction', 'notes']));

            PieceworkEntry::whereIn('payroll_line_id', $payroll->lines()->pluck('id'))->update(['payroll_line_id' => null]);
            $payroll->lines()->delete();

            $this->collect($payroll, $adjustments->all());
            ActivityLog::record('updated', 'Menghitung ulang hasil kerja, total '.rupiah($payroll->total_net), $payroll);
        });
    }

    /**
     * Ubah bonus/tunjangan, potongan kasbon, potongan lain, dan catatan per slip (hanya draft).
     *
     * @param  array<int, array{allowance?: float|string|null, advance_deduction?: float|string|null, other_deduction?: float|string|null, notes?: string|null}>  $values  payroll_line_id => nilai
     */
    public function updateLines(Payroll $payroll, array $values): void
    {
        $this->assertDraft($payroll);

        DB::transaction(function () use ($payroll, $values) {
            foreach ($payroll->lines()->with('employee')->get() as $line) {
                if (! isset($values[$line->id])) {
                    continue;
                }

                $v = $values[$line->id];
                $allowance = round(max(0, (float) ($v['allowance'] ?? 0)), 2);
                $advance = round(max(0, (float) ($v['advance_deduction'] ?? 0)), 2);
                $other = round(max(0, (float) ($v['other_deduction'] ?? 0)), 2);
                $balance = $line->employee->advanceBalance($line->id);

                if ($advance > $balance + 0.001) {
                    throw ValidationException::withMessages(["lines.{$line->id}.advance_deduction" => "Potongan kasbon {$line->employee->name} melebihi sisa kasbon (".rupiah($balance).').']);
                }

                $net = round((float) $line->gross + $allowance - $advance - $other, 2);

                if ($net < 0) {
                    throw ValidationException::withMessages(["lines.{$line->id}.other_deduction" => "Total potongan {$line->employee->name} melebihi upah yang diterima."]);
                }

                $line->update([
                    'allowance' => $allowance,
                    'advance_deduction' => $advance,
                    'other_deduction' => $other,
                    'net' => $net,
                    'notes' => $v['notes'] ?? null,
                ]);
            }

            $this->refreshTotals($payroll);
            ActivityLog::record('updated', 'Mengubah bonus / potongan slip upah, total dibayar '.rupiah($payroll->total_net), $payroll);
        });
    }

    public function approve(Payroll $payroll): void
    {
        $this->assertDraft($payroll);

        DB::transaction(function () use ($payroll) {
            $payroll->update(['status' => PayrollStatus::Approved, 'approved_by' => auth()->id(), 'approved_at' => now()]);
            ActivityLog::record('approved', 'Menyetujui penggajian borongan, total '.rupiah($payroll->total_net), $payroll);
        });
    }

    /**
     * @param  array{payment_date: string, payment_method: string}  $data
     */
    public function pay(Payroll $payroll, array $data): void
    {
        if ($payroll->status !== PayrollStatus::Approved) {
            throw ValidationException::withMessages(['status' => 'Penggajian harus disetujui sebelum dibayar.']);
        }

        $date = Carbon::parse($data['payment_date']);

        if ($date->lt($payroll->period_start)) {
            throw ValidationException::withMessages(['payment_date' => 'Tanggal bayar tidak boleh sebelum awal periode.']);
        }

        DB::transaction(function () use ($payroll, $data, $date) {
            $payroll->update([
                'status' => PayrollStatus::Paid,
                'payment_date' => $date,
                'payment_method' => PaymentMethod::from($data['payment_method']),
                'cash_account_id' => $data['cash_account_id'] ?? null,
                'paid_by' => auth()->id(),
            ]);
            $this->journal->payrollPayment($payroll);
            ActivityLog::record('paid', 'Membayar upah borongan '.rupiah($payroll->total_net).' ('.$payroll->payment_method->label().')', $payroll);
        });
    }

    public function cancel(Payroll $payroll, ?string $reason = null): void
    {
        if ($payroll->status === PayrollStatus::Cancelled) {
            throw ValidationException::withMessages(['status' => 'Penggajian sudah dibatalkan.']);
        }

        DB::transaction(function () use ($payroll, $reason) {
            if ($payroll->status === PayrollStatus::Paid) {
                $this->journal->reverse($payroll, 'pembayaran upah dibatalkan'.($reason ? ": {$reason}" : ''));
            }

            PieceworkEntry::whereIn('payroll_line_id', $payroll->lines()->pluck('id'))->update(['payroll_line_id' => null]);
            $payroll->update(['status' => PayrollStatus::Cancelled]);
            ActivityLog::record('cancelled', 'Membatalkan penggajian borongan'.($reason ? ": {$reason}" : '').'; hasil kerja dilepas untuk penggajian berikutnya', $payroll);
        });
    }

    /**
     * @param  array{employee_id: int|string, date: string, amount: float|string, payment_method: string, notes?: string|null}  $data
     */
    public function recordAdvance(array $data): EmployeeAdvance
    {
        $employee = Employee::findOrFail($data['employee_id']);
        $amount = round((float) $data['amount'], 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Jumlah kasbon harus lebih dari 0.']);
        }

        return DB::transaction(function () use ($employee, $data, $amount) {
            $date = Carbon::parse($data['date']);
            $advance = EmployeeAdvance::create([
                'number' => DocumentNumber::generate(EmployeeAdvance::class, 'KSB', $date),
                'employee_id' => $employee->id,
                'date' => $date,
                'amount' => $amount,
                'payment_method' => PaymentMethod::from($data['payment_method']),
                'cash_account_id' => $data['cash_account_id'] ?? null,
                'status' => ReturnStatus::Posted,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);
            $this->journal->employeeAdvance($advance);
            ActivityLog::record('advance', "Mencatat kasbon {$advance->number} ".rupiah($amount), $employee);

            return $advance;
        });
    }

    public function cancelAdvance(EmployeeAdvance $advance): void
    {
        if ($advance->status !== ReturnStatus::Posted) {
            throw ValidationException::withMessages(['status' => 'Kasbon sudah dibatalkan.']);
        }

        $employee = $advance->employee;

        if ($employee->advanceBalance() + 0.001 < (float) $advance->amount) {
            throw ValidationException::withMessages(['status' => 'Kasbon ini sudah (sebagian) dipotong dari upah. Batalkan penggajian terkait atau ubah potongannya terlebih dahulu.']);
        }

        DB::transaction(function () use ($advance, $employee) {
            $this->journal->reverse($advance, 'kasbon dibatalkan');
            $advance->update(['status' => ReturnStatus::Cancelled]);
            ActivityLog::record('advance', "Membatalkan kasbon {$advance->number} ".rupiah($advance->amount), $employee);
        });
    }

    /**
     * Kelompokkan hasil kerja yang belum dibayar sampai akhir periode menjadi slip per karyawan.
     *
     * @param  array<int, array<string, mixed>>  $adjustments  employee_id => bonus/potongan sebelumnya
     * @return int jumlah slip
     */
    private function collect(Payroll $payroll, array $adjustments = []): int
    {
        $entries = PieceworkEntry::whereNull('payroll_line_id')
            ->whereHas('sheet', fn ($q) => $q->whereDate('date', '<=', $payroll->period_end))
            ->lockForUpdate()
            ->get()
            ->groupBy('employee_id');

        $employees = Employee::whereIn('id', $entries->keys())->orderBy('name')->get();

        foreach ($employees as $employee) {
            $rows = $entries[$employee->id];
            $gross = round((float) $rows->sum('amount'), 2);
            $adj = $adjustments[$employee->id] ?? null;
            $allowance = (float) ($adj['allowance'] ?? 0);
            $other = (float) ($adj['other_deduction'] ?? 0);
            // Default: potong seluruh sisa kasbon selama upah mencukupi
            $advance = min(
                $employee->advanceBalance(),
                $adj !== null ? (float) $adj['advance_deduction'] : PHP_FLOAT_MAX,
                max(0, $gross + $allowance - $other),
            );

            $line = $payroll->lines()->create([
                'employee_id' => $employee->id,
                'quantity' => round((float) $rows->sum('quantity'), 2),
                'gross' => $gross,
                'allowance' => $allowance,
                'advance_deduction' => round($advance, 2),
                'other_deduction' => $other,
                'net' => round($gross + $allowance - $advance - $other, 2),
                'notes' => $adj['notes'] ?? null,
            ]);

            PieceworkEntry::whereIn('id', $rows->pluck('id'))->update(['payroll_line_id' => $line->id]);
        }

        $this->refreshTotals($payroll);

        return $employees->count();
    }

    private function refreshTotals(Payroll $payroll): void
    {
        $lines = $payroll->lines()->get();

        $payroll->update([
            'total_gross' => round((float) $lines->sum('gross'), 2),
            'total_allowance' => round((float) $lines->sum('allowance'), 2),
            'total_deduction' => round($lines->sum(fn (PayrollLine $l) => $l->totalDeduction()), 2),
            'total_net' => round((float) $lines->sum('net'), 2),
        ]);
    }

    private function assertDraft(Payroll $payroll): void
    {
        if (! $payroll->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Hanya penggajian berstatus Draft yang dapat diubah.']);
        }
    }
}
