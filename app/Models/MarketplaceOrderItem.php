<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceOrderItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'units_per_item' => 'decimal:2',
        'quantity' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'dpp' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'cost' => 'decimal:2',
        'returned_quantity' => 'decimal:2',
        'restocked_quantity' => 'decimal:2',
        'defect_quantity' => 'decimal:2',
    ];

    public function order()
    {
        return $this->belongsTo(MarketplaceOrder::class, 'marketplace_order_id');
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** Jumlah pcs ERP yang keluar dari gudang (qty pesanan × isi per paket). */
    public function stockQuantity(): float
    {
        return round((float) $this->quantity * (float) $this->units_per_item, 2);
    }

    public function label(): string
    {
        return trim(($this->product_name ?: $this->marketplace_sku ?: '-').($this->variation ? ' ('.$this->variation.')' : ''));
    }
}
