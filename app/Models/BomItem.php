<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BomItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:4',
        'waste_percent' => 'decimal:2',
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

    public function requiredFor(float $quantity): float
    {
        return round($this->factor() * $quantity, 2);
    }
}
