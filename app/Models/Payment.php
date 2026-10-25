<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\Concerns\HasActivityLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Penerimaan dari pelanggan (RV) atau pembayaran ke supplier (PV).
 * Satu pembayaran bisa dialokasikan ke beberapa invoice/tagihan sekaligus.
 */
class Payment extends Model
{
    use HasActivityLog;

    protected $guarded = ['id'];

    protected $casts = [
        'payment_date' => 'date',
        'type' => PaymentType::class,
        'method' => PaymentMethod::class,
        'status' => PaymentStatus::class,
        'amount' => 'decimal:2',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function allocations()
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function party(): Customer|Supplier|null
    {
        return $this->type === PaymentType::Receipt ? $this->customer : $this->supplier;
    }

    public function isCancelled(): bool
    {
        return $this->status === PaymentStatus::Cancelled;
    }
}
