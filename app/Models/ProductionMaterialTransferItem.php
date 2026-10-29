<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductionMaterialTransferItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_cost' => 'decimal:4',
    ];

    public function transfer()
    {
        return $this->belongsTo(ProductionMaterialTransfer::class, 'production_material_transfer_id');
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }
}
