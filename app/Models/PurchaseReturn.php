<?php

namespace App\Models;

use App\Enums\ReturnStatus;
use App\Models\Concerns\HasActivityLog;
use App\Models\Concerns\HasWarehouse;
use App\Models\Concerns\ReturnNote;
use Illuminate\Database\Eloquent\Model;

/**
 * Retur pembelian ke supplier. Bahan keluar dari stok; bagian yang sudah ditagih supplier
 * menjadi nota debit yang mengurangi hutang.
 */
class PurchaseReturn extends Model
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

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseReturnItem::class);
    }

    public function party(): Supplier
    {
        return $this->supplier;
    }
}
