<?php

namespace App\Models;

use App\Enums\PurchaseOrderStatus;
use App\Models\Concerns\HasActivityLog;
use Illuminate\Database\Eloquent\Model;

class PurchaseOrder extends Model
{
    use HasActivityLog;

    protected $guarded = ['id'];

    protected $casts = [
        'order_date' => 'date',
        'expected_date' => 'date',
        'status' => PurchaseOrderStatus::class,
        'total' => 'decimal:2',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function receipts()
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function bills()
    {
        return $this->hasMany(Bill::class);
    }

    /** Masih ada barang diterima yang belum ditagih supplier. */
    public function canBill(): bool
    {
        return $this->status !== PurchaseOrderStatus::Cancelled
            && $this->items->contains(fn ($item) => $item->unbilledQuantity() > 0);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEditable(): bool
    {
        return $this->status === PurchaseOrderStatus::Draft;
    }

    public function canReceive(): bool
    {
        return in_array($this->status, [PurchaseOrderStatus::Ordered, PurchaseOrderStatus::Partial], true);
    }

    public function refreshReceiptStatus(): void
    {
        $this->load('items');
        $allReceived = $this->items->every(fn ($item) => $item->outstandingQuantity() <= 0);
        $anyReceived = $this->items->contains(fn ($item) => (float) $item->received_quantity > 0);

        $this->status = $allReceived
            ? PurchaseOrderStatus::Received
            : ($anyReceived ? PurchaseOrderStatus::Partial : PurchaseOrderStatus::Ordered);
        $this->save();
    }
}
