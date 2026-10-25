<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bom extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean'];

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
}
