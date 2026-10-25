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
        'returned_quantity' => 'decimal:2',
        'returned_invoiced_quantity' => 'decimal:2',
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

    public function deliveryItems()
    {
        return $this->hasMany(DeliveryItem::class);
    }

    /**
     * HPP rata-rata per pcs dari semua surat jalan baris ini. Dipakai sebagai HPP invoice
     * karena invoice menagih barang terkirim tanpa menunjuk surat jalan tertentu.
     */
    public function deliveredUnitCost(): float
    {
        $totals = $this->deliveryItems()->selectRaw('COALESCE(SUM(quantity), 0) as qty, COALESCE(SUM(cost), 0) as cost')->first();

        return (float) $totals->qty > 0 ? round((float) $totals->cost / (float) $totals->qty, 2) : 0.0;
    }

    public function outstandingQuantity(): float
    {
        return max(0, (float) $this->quantity - (float) $this->delivered_quantity);
    }

    /**
     * Jumlah yang sudah dikirim tetapi belum dibuatkan invoice. Retur atas barang yang belum
     * ditagih mengurangi jumlah ini (barangnya kembali, jadi tidak ditagihkan).
     */
    public function uninvoicedQuantity(): float
    {
        $returnedUninvoiced = (float) $this->returned_quantity - (float) $this->returned_invoiced_quantity;

        return max(0, round((float) $this->delivered_quantity - (float) $this->invoiced_quantity - $returnedUninvoiced, 2));
    }

    /** Barang terkirim yang masih dapat diretur pelanggan. */
    public function returnableQuantity(): float
    {
        return max(0, round((float) $this->delivered_quantity - (float) $this->returned_quantity, 2));
    }
}
