<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Slip upah satu karyawan dalam satu penggajian.
 */
class PayrollLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:2',
        'gross' => 'decimal:2',
        'allowance' => 'decimal:2',
        'advance_deduction' => 'decimal:2',
        'other_deduction' => 'decimal:2',
        'net' => 'decimal:2',
    ];

    public function payroll()
    {
        return $this->belongsTo(Payroll::class);
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function entries()
    {
        return $this->hasMany(PieceworkEntry::class);
    }

    public function totalDeduction(): float
    {
        return round((float) $this->advance_deduction + (float) $this->other_deduction, 2);
    }
}
