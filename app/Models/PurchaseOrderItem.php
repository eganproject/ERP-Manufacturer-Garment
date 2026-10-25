<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseOrderItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:2',
        'received_quantity' => 'decimal:2',
        'billed_quantity' => 'decimal:2',
        'price' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    public function outstandingQuantity(): float
    {
        return max(0, (float) $this->quantity - (float) $this->received_quantity);
    }

    /** Jumlah yang sudah diterima tetapi belum ditagih supplier. */
    public function unbilledQuantity(): float
    {
        return max(0, (float) $this->received_quantity - (float) $this->billed_quantity);
    }
}
