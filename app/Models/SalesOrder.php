<?php

namespace App\Models;

use App\Enums\SalesOrderStatus;
use App\Models\Concerns\HasActivityLog;
use Illuminate\Database\Eloquent\Model;

class SalesOrder extends Model
{
    use HasActivityLog;

    protected $guarded = ['id'];

    protected $casts = [
        'order_date' => 'date',
        'delivery_date' => 'date',
        'status' => SalesOrderStatus::class,
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    public function productionOrders()
    {
        return $this->hasMany(ProductionOrder::class);
    }

    public function deliveries()
    {
        return $this->hasMany(Delivery::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function returns()
    {
        return $this->hasMany(SalesReturn::class);
    }

    /** Masih ada barang terkirim yang dapat diretur pelanggan. */
    public function canReturn(): bool
    {
        return $this->status !== SalesOrderStatus::Cancelled
            && $this->items->contains(fn ($item) => $item->returnableQuantity() > 0);
    }

    /** Masih ada barang terkirim yang belum dibuatkan invoice. */
    public function canInvoice(): bool
    {
        return $this->status !== SalesOrderStatus::Cancelled
            && $this->items->contains(fn ($item) => $item->uninvoicedQuantity() > 0);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEditable(): bool
    {
        return $this->status === SalesOrderStatus::Draft;
    }

    public function canDeliver(): bool
    {
        return in_array($this->status, SalesOrderStatus::open(), true);
    }

    public function canCancel(): bool
    {
        return in_array($this->status, [SalesOrderStatus::Draft, SalesOrderStatus::Confirmed], true);
    }

    public function refreshDeliveryStatus(): void
    {
        $this->load('items');
        $allDelivered = $this->items->every(fn ($item) => $item->outstandingQuantity() <= 0);
        $anyDelivered = $this->items->contains(fn ($item) => (float) $item->delivered_quantity > 0);

        $this->status = $allDelivered
            ? SalesOrderStatus::Delivered
            : ($anyDelivered ? SalesOrderStatus::Partial : SalesOrderStatus::Confirmed);
        $this->save();
    }
}
