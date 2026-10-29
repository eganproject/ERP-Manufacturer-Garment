<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionOrderItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:2',
        'finished_quantity' => 'decimal:2',
        'defect_quantity' => 'decimal:2',
    ];

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    /** Pcs yang belum disetor ke gudang (bagus maupun grade B). */
    public function remainingQuantity(): float
    {
        return max(0, round((float) $this->quantity - (float) $this->finished_quantity - (float) $this->defect_quantity, 2));
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
