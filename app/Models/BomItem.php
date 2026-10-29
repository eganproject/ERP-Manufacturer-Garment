<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BomItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:4',
        'waste_percent' => 'decimal:2',
        'sizes' => 'array',
        'colors' => 'array',
    ];

    public function bom()
    {
        return $this->belongsTo(Bom::class);
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    /** Kebutuhan per 1 pcs termasuk waste. */
    public function factor(): float
    {
        return (float) $this->quantity * (1 + (float) $this->waste_percent / 100);
    }

    /** Baris dengan batasan ukuran / warna (tidak berlaku untuk semua varian). */
    public function isConditional(): bool
    {
        return ! empty($this->sizes) || ! empty($this->colors);
    }

    /** Apakah baris ini dipakai untuk varian tersebut (kosong = semua ukuran / warna). */
    public function appliesTo(ProductVariant $variant): bool
    {
        $match = fn (?array $list, ?string $value) => empty($list)
            || in_array(mb_strtolower(trim((string) $value)), array_map(fn ($v) => mb_strtolower(trim((string) $v)), $list), true);

        return $match($this->sizes, $variant->size) && $match($this->colors, $variant->color);
    }

    /** Keterangan singkat batasan varian, mis. "Ukuran XL, XXL · Warna Hitam". */
    public function scopeLabel(): ?string
    {
        $parts = array_filter([
            ! empty($this->sizes) ? 'Ukuran '.implode(', ', $this->sizes) : null,
            ! empty($this->colors) ? 'Warna '.implode(', ', $this->colors) : null,
        ]);

        return $parts ? implode(' · ', $parts) : null;
    }

    public function requiredFor(float $quantity): float
    {
        return round($this->factor() * $quantity, 2);
    }
}
