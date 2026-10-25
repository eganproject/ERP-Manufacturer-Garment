<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SalesReturnItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:2',
        'defect_quantity' => 'decimal:2',
        'billed_quantity' => 'decimal:2',
        'price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'amount' => 'decimal:2',
        'unit_cost' => 'decimal:4',
        'cost' => 'decimal:2',
        'billed_cost' => 'decimal:2',
    ];

    public function salesReturn()
    {
        return $this->belongsTo(SalesReturn::class);
    }

    public function salesOrderItem()
    {
        return $this->belongsTo(SalesOrderItem::class);
    }

    /** Nilai HPP bagian barang cacat yang masuk ke stok cacat. */
    public function defectCost(): float
    {
        return round((float) $this->defect_quantity * (float) $this->unit_cost, 2);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
