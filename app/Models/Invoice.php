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
        'credited_amount' => 'decimal:2',
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

    /** Penjualan bersih (DPP): subtotal dikurangi diskon, sebelum PPN. */
    public function netSales(): float
    {
        return round((float) $this->subtotal - (float) $this->discount, 2);
    }

    /** HPP barang yang ditagih pada invoice ini. */
    public function costOfGoods(): float
    {
        return round((float) $this->items->sum('cost'), 2);
    }

    public function grossProfit(): float
    {
        return round($this->netSales() - $this->costOfGoods(), 2);
    }

    public function party(): Customer
    {
        return $this->customer;
    }
}
