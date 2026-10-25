<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesOrderItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:2',
        'delivered_quantity' => 'decimal:2',
        'invoiced_quantity' => 'decimal:2',
        'price' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function outstandingQuantity(): float
    {
        return max(0, (float) $this->quantity - (float) $this->delivered_quantity);
    }

    /** Jumlah yang sudah dikirim tetapi belum dibuatkan invoice. */
    public function uninvoicedQuantity(): float
    {
        return max(0, (float) $this->delivered_quantity - (float) $this->invoiced_quantity);
    }
}
