<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionMaterial extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'required_quantity' => 'decimal:2',
        'issued_quantity' => 'decimal:2',
        'returned_quantity' => 'decimal:2',
        'unit_cost' => 'decimal:4',
    ];

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    /** Biaya bahan: aktual bila sudah dikeluarkan, estimasi harga pokok saat ini bila belum. */
    public function cost(): float
    {
        if ((float) $this->issued_quantity > 0 || (float) $this->returned_quantity > 0) {
            return round((float) $this->issued_quantity * (float) $this->unit_cost, 2);
        }

        return round((float) $this->required_quantity * $this->material->costPrice(), 2);
    }
}
