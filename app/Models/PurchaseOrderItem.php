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
        'returned_quantity' => 'decimal:2',
        'returned_billed_quantity' => 'decimal:2',
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

    /**
     * Jumlah yang sudah diterima tetapi belum ditagih supplier. Retur atas barang yang belum
     * ditagih mengurangi jumlah ini (barangnya dikembalikan, jadi tidak boleh ditagih).
     */
    public function unbilledQuantity(): float
    {
        $returnedUnbilled = (float) $this->returned_quantity - (float) $this->returned_billed_quantity;

        return max(0, round((float) $this->received_quantity - (float) $this->billed_quantity - $returnedUnbilled, 2));
    }

    /** Barang diterima yang masih dapat diretur ke supplier. */
    public function returnableQuantity(): float
    {
        return max(0, round((float) $this->received_quantity - (float) $this->returned_quantity, 2));
    }

    public function billItems()
    {
        return $this->hasMany(BillItem::class);
    }
}
