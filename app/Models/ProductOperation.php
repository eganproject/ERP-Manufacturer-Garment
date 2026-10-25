<?php

namespace App\Models;

use App\Enums\ProductionStage;
use Illuminate\Database\Eloquent\Model;

/**
 * Operasi / segmen jahit per artikel beserta tarif borongan per pcs.
 */
class ProductOperation extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'stage' => ProductionStage::class,
        'rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function entries()
    {
        return $this->hasMany(PieceworkEntry::class);
    }
}
