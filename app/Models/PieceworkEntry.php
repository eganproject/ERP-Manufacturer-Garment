<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Hasil kerja satu karyawan pada satu operasi (atau pcs jadi) di lembar hasil kerja.
 */
class PieceworkEntry extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:2',
        'reject_quantity' => 'decimal:2',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function sheet()
    {
        return $this->belongsTo(PieceworkSheet::class, 'piecework_sheet_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function operation()
    {
        return $this->belongsTo(ProductOperation::class, 'product_operation_id');
    }

    public function payrollLine()
    {
        return $this->belongsTo(PayrollLine::class);
    }

    public function isFullGarment(): bool
    {
        return $this->product_operation_id === null;
    }
}
