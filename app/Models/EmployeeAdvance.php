<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\ReturnStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Kasbon (pinjaman) karyawan, dipotong dari upah borongan.
 */
class EmployeeAdvance extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'date',
        'amount' => 'decimal:2',
        'payment_method' => PaymentMethod::class,
        'status' => ReturnStatus::class,
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
