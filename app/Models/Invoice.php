<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\HasActivityLog;
use App\Models\Concerns\Payable;
use Illuminate\Database\Eloquent\Model;

/**
 * Invoice penjualan ke pelanggan (piutang), dibuat dari barang yang sudah dikirim.
 */
class Invoice extends Model
{
    use HasActivityLog, Payable;

    protected $guarded = ['id'];

    protected $casts = [
        'invoice_date' => 'date',
        'due_date' => 'date',
        'status' => InvoiceStatus::class,
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function party(): Customer
    {
        return $this->customer;
    }
}
