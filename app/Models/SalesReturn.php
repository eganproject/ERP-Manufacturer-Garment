<?php

namespace App\Models;

use App\Enums\ReturnStatus;
use App\Models\Concerns\HasActivityLog;
use App\Models\Concerns\HasWarehouse;
use App\Models\Concerns\ReturnNote;
use Illuminate\Database\Eloquent\Model;

/**
 * Retur penjualan dari pelanggan. Barang kembali ke stok barang jadi; bagian yang sudah
 * ditagih menjadi nota kredit yang mengurangi piutang.
 */
class SalesReturn extends Model
{
    use HasActivityLog, HasWarehouse, ReturnNote;

    protected $guarded = ['id'];

    protected $casts = [
        'return_date' => 'date',
        'refund_date' => 'date',
        'status' => ReturnStatus::class,
        'amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'applied_amount' => 'decimal:2',
        'refunded_amount' => 'decimal:2',
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
        return $this->hasMany(SalesReturnItem::class);
    }

    public function party(): Customer
    {
        return $this->customer;
    }
}
