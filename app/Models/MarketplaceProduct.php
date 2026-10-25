<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Pemetaan SKU toko marketplace ke varian ERP (units_per_item > 1 = paket bundling). */
class MarketplaceProduct extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'units_per_item' => 'decimal:2',
    ];

    public function store()
    {
        return $this->belongsTo(MarketplaceStore::class, 'marketplace_store_id');
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
