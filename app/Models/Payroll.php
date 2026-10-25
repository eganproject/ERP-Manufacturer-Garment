<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PayrollStatus;
use App\Models\Concerns\HasActivityLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Penggajian borongan untuk satu periode (mis. mingguan).
 */
class Payroll extends Model
{
    use HasActivityLog;

    protected $guarded = ['id'];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'payment_date' => 'date',
        'status' => PayrollStatus::class,
        'payment_method' => PaymentMethod::class,
        'total_gross' => 'decimal:2',
        'total_allowance' => 'decimal:2',
        'total_deduction' => 'decimal:2',
        'total_net' => 'decimal:2',
        'approved_at' => 'datetime',
    ];

    public function lines()
    {
        return $this->hasMany(PayrollLine::class)->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function payer()
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function periodLabel(): string
    {
        return tanggal($this->period_start).' – '.tanggal($this->period_end);
    }

    public function isDraft(): bool
    {
        return $this->status === PayrollStatus::Draft;
    }
}
