<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubcontractTransferItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_cost' => 'decimal:4',
    ];

    public function transfer()
    {
        return $this->belongsTo(SubcontractTransfer::class, 'subcontract_transfer_id');
    }

    public function orderMaterial()
    {
        return $this->belongsTo(SubcontractOrderMaterial::class, 'subcontract_order_material_id');
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }
}
