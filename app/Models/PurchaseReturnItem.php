<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReturnItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:2',
        'billed_quantity' => 'decimal:2',
        'price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'amount' => 'decimal:2',
        'unit_cost' => 'decimal:4',
        'cost' => 'decimal:2',
        'billed_cost' => 'decimal:2',
    ];

    public function purchaseReturn()
    {
        return $this->belongsTo(PurchaseReturn::class);
    }

    public function purchaseOrderItem()
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }
}
