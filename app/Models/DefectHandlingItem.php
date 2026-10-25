<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DefectHandlingItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_cost' => 'decimal:4',
        'cost' => 'decimal:2',
        'extra_cost' => 'decimal:2',
    ];

    public function defectHandling()
    {
        return $this->belongsTo(DefectHandling::class);
    }

    public function variant()
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
