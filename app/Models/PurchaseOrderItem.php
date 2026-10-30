<?php

namespace App\Models;

use App\Support\UnitConversion;
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
        'conversion' => 'decimal:6',
    ];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    /** Satuan baris PO (satuan beli); kosong = satuan stok bahan. */
    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function unitCode(): ?string
    {
        return $this->unit?->code ?? $this->material?->unit?->code;
    }

    /** Jumlah satuan stok dalam 1 satuan baris ini. */
    public function factor(): float
    {
        return (float) $this->conversion > 0 ? (float) $this->conversion : 1.0;
    }

    public function isConverted(): bool
    {
        return abs($this->factor() - 1) > 0.0000001;
    }

    /** Qty satuan baris → qty satuan stok. */
    public function toStock(float $quantity): float
    {
        return round($quantity * $this->factor(), 2);
    }

    /**
     * Harga per satuan stok (dasar harga pokok rata-rata). Dengan $quantity, harga dihitung dari nilai
     * penerimaan ÷ qty stok yang dibulatkan, agar nilai persediaan = qty beli × harga PO (sama dengan jurnal).
     */
    public function stockUnitPrice(?float $quantity = null): float
    {
        $stockQty = $quantity !== null ? $this->toStock($quantity) : 0;

        return $stockQty > 0
            ? $quantity * (float) $this->price / $stockQty
            : (float) $this->price / $this->factor();
    }

    /** Teks konversi untuk tampilan, mis. "1 YD = 0,9144 M". */
    public function conversionLabel(): ?string
    {
        return $this->isConverted()
            ? '1 '.$this->unitCode().' = '.UnitConversion::format($this->factor()).' '.$this->material?->unit?->code
            : null;
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
