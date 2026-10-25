<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bom extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
        'labor_cost' => 'decimal:2',
        'overhead_cost' => 'decimal:2',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function items()
    {
        return $this->hasMany(BomItem::class);
    }

    /**
     * Hitung kebutuhan bahan untuk sejumlah pcs.
     *
     * @return array<int, float> material_id => qty
     */
    public function requirementsFor(float $quantity): array
    {
        $result = [];

        foreach ($this->items as $item) {
            $result[$item->material_id] = ($result[$item->material_id] ?? 0) + $item->requiredFor($quantity);
        }

        return $result;
    }

    /** Biaya bahan standar per 1 pcs (termasuk waste) berdasarkan harga pokok bahan saat ini. */
    public function standardMaterialCost(): float
    {
        $this->loadMissing('items.material');

        return round($this->items->sum(fn (BomItem $item) => $item->factor() * $item->material->costPrice()), 2);
    }

    /** HPP standar per 1 pcs: bahan + upah/CMT + overhead. */
    public function standardUnitCost(): float
    {
        return round($this->standardMaterialCost() + (float) $this->labor_cost + (float) $this->overhead_cost, 2);
    }
}
