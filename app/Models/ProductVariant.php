<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    protected $guarded = ['id', 'stock', 'average_cost', 'defect_stock', 'defect_cost'];

    protected $casts = [
        'price' => 'decimal:2',
        'average_cost' => 'decimal:2',
        'stock' => 'decimal:2',
        'defect_stock' => 'decimal:2',
        'defect_cost' => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function stockMovements()
    {
        return $this->morphMany(StockMovement::class, 'stockable');
    }

    public function getSellingPriceAttribute(): float
    {
        return (float) ($this->price ?? $this->product?->price ?? 0);
    }

    public function getDisplayNameAttribute(): string
    {
        $name = $this->product?->name.' - '.$this->size;

        return $this->color ? "{$name} / {$this->color}" : $name;
    }
}
