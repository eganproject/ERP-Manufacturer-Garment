<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    use HasFactory;

    protected $guarded = ['id', 'stock', 'average_cost'];

    protected $casts = [
        'price' => 'decimal:2',
        'average_cost' => 'decimal:4',
        'min_stock' => 'decimal:2',
        'stock' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function defaultSupplier()
    {
        return $this->belongsTo(Supplier::class, 'default_supplier_id');
    }

    public function purchaseOrderItems()
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /** Harga pokok per satuan untuk HPP: rata-rata tertimbang, atau harga standar bila belum ada. */
    public function costPrice(): float
    {
        return (float) $this->average_cost > 0 ? (float) $this->average_cost : (float) $this->price;
    }

    public function stockMovements()
    {
        return $this->morphMany(StockMovement::class, 'stockable');
    }

    public function isLowStock(): bool
    {
        return (float) $this->stock <= (float) $this->min_stock;
    }

    public function getDisplayNameAttribute(): string
    {
        return "{$this->code} - {$this->name}".($this->color ? " ({$this->color})" : '');
    }
}
