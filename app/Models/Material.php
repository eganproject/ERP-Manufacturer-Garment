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
        'purchase_conversion' => 'decimal:6',
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

    /** Satuan beli (mis. YD) bila berbeda dari satuan stok; kosong = dibeli dalam satuan stok. */
    public function purchaseUnit()
    {
        return $this->belongsTo(Unit::class, 'purchase_unit_id');
    }

    public function hasPurchaseUnit(): bool
    {
        return $this->purchase_unit_id && $this->purchase_unit_id !== $this->unit_id && (float) $this->purchase_conversion > 0;
    }

    /** Satuan default di PO: satuan beli bila ada, selain itu satuan stok. */
    public function defaultPurchaseUnitId(): int
    {
        return $this->hasPurchaseUnit() ? (int) $this->purchase_unit_id : (int) $this->unit_id;
    }

    /** Faktor satuan $unitId → satuan stok (1 bila satuan stok atau tidak dikenal). */
    public function conversionFor(?int $unitId): float
    {
        return $this->hasPurchaseUnit() && $unitId === (int) $this->purchase_unit_id ? (float) $this->purchase_conversion : 1.0;
    }

    /** Pilihan satuan di PO: [unit_id => [kode, faktor ke satuan stok]]. */
    public function purchaseUnitOptions(): array
    {
        $options = [];
        if ($this->hasPurchaseUnit()) {
            $options[(int) $this->purchase_unit_id] = [$this->purchaseUnit?->code, (float) $this->purchase_conversion];
        }
        $options[(int) $this->unit_id] = [$this->unit?->code, 1.0];

        return $options;
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

    public function warehouseStocks()
    {
        return $this->morphMany(WarehouseStock::class, 'stockable');
    }

    /** Stok jual (atau stok cacat) di satu gudang. */
    public function stockIn(Warehouse|int $warehouse, bool $defect = false): float
    {
        $id = $warehouse instanceof Warehouse ? $warehouse->id : $warehouse;
        $row = $this->relationLoaded('warehouseStocks')
            ? $this->warehouseStocks->firstWhere('warehouse_id', $id)
            : $this->warehouseStocks()->where('warehouse_id', $id)->first();

        return (float) ($row?->{$defect ? 'defect_quantity' : 'quantity'} ?? 0);
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
