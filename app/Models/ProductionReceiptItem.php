<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionReceiptItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:2',
        'defect_quantity' => 'decimal:2',
    ];

    public function receipt()
    {
        return $this->belongsTo(ProductionReceipt::class, 'production_receipt_id');
    }

    public function orderItem()
    {
        return $this->belongsTo(ProductionOrderItem::class, 'production_order_item_id');
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
