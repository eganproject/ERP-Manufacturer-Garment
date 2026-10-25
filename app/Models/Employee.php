<?php

namespace App\Models;

use App\Enums\EmployeeWorkType;
use App\Enums\PayrollStatus;
use App\Enums\ReturnStatus;
use App\Models\Concerns\HasActivityLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Karyawan produksi yang dibayar borongan (per pcs jadi atau per operasi/segmen).
 */
class Employee extends Model
{
    use HasActivityLog;

    protected $guarded = ['id'];

    protected $casts = [
        'work_type' => EmployeeWorkType::class,
        'join_date' => 'date',
        'is_active' => 'boolean',
    ];

    public function entries()
    {
        return $this->hasMany(PieceworkEntry::class);
    }

    public function payrollLines()
    {
        return $this->hasMany(PayrollLine::class);
    }

    public function advances()
    {
        return $this->hasMany(EmployeeAdvance::class)->latest('date')->latest('id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->code.' - '.$this->name;
    }

    /**
     * Sisa kasbon: total kasbon − potongan di penggajian yang belum dibatalkan (termasuk draft),
     * sehingga kasbon yang sudah dijadwalkan dipotong tidak dipotong dua kali.
     */
    public function advanceBalance(?int $exceptPayrollLineId = null): float
    {
        $advances = (float) $this->advances()->where('status', ReturnStatus::Posted)->sum('amount');
        $deducted = (float) $this->payrollLines()
            ->whereHas('payroll', fn ($q) => $q->whereIn('status', PayrollStatus::active()))
            ->when($exceptPayrollLineId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->sum('advance_deduction');

        return round(max(0, $advances - $deducted), 2);
    }

    /** Hasil kerja yang belum masuk penggajian. */
    public function unpaidAmount(): float
    {
        return round((float) $this->entries()->whereNull('payroll_line_id')->sum('amount'), 2);
    }
}
