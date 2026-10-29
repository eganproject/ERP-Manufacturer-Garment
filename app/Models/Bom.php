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

    /** BOM punya baris yang hanya berlaku untuk ukuran / warna tertentu. */
    public function isPerVariant(): bool
    {
        return $this->items->contains(fn (BomItem $item) => $item->isConditional());
    }

    /**
     * Hitung kebutuhan bahan untuk sejumlah pcs (semua baris, tanpa memperhatikan ukuran / warna).
     * Untuk BOM per varian pakai requirementsForVariants().
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

    /**
     * Kebutuhan bahan per jumlah tiap varian: baris BOM hanya dihitung untuk varian yang cocok ukuran / warnanya.
     *
     * @param  array<int, float>  $variantQuantities  product_variant_id => qty
     * @return array<int, float> material_id => qty
     */
    public function requirementsForVariants(array $variantQuantities): array
    {
        $variants = ProductVariant::whereIn('id', array_keys($variantQuantities))->get()->keyBy('id');
        $result = [];

        foreach ($this->items as $item) {
            $quantity = 0.0;

            foreach ($variantQuantities as $variantId => $qty) {
                $variant = $variants->get($variantId);

                if ($variant && $item->appliesTo($variant)) {
                    $quantity += (float) $qty;
                }
            }

            if ($quantity > 0) {
                $result[$item->material_id] = round(($result[$item->material_id] ?? 0) + $item->requiredFor($quantity), 2);
            }
        }

        return $result;
    }

    /**
     * Biaya bahan standar per 1 pcs (termasuk waste) berdasarkan harga pokok bahan saat ini.
     * Dengan varian: hanya baris yang berlaku untuk varian itu. Tanpa varian pada BOM per varian:
     * rata-rata semua varian aktif produk.
     */
    public function standardMaterialCost(?ProductVariant $variant = null): float
    {
        $this->loadMissing('items.material');

        if ($variant) {
            return round($this->items->filter(fn (BomItem $item) => $item->appliesTo($variant))
                ->sum(fn (BomItem $item) => $item->factor() * $item->material->costPrice()), 2);
        }

        if ($this->isPerVariant() && ($variants = $this->product->variants()->get())->isNotEmpty()) {
            return round($variants->avg(fn (ProductVariant $v) => $this->standardMaterialCost($v)), 2);
        }

        return round($this->items->sum(fn (BomItem $item) => $item->factor() * $item->material->costPrice()), 2);
    }

    /** HPP standar per 1 pcs: bahan + upah/CMT + overhead. */
    public function standardUnitCost(?ProductVariant $variant = null): float
    {
        return round($this->standardMaterialCost($variant) + (float) $this->labor_cost + (float) $this->overhead_cost, 2);
    }
}
