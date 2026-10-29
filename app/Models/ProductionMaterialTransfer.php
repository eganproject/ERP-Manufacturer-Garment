<?php

namespace App\Models;

use App\Enums\ProductionMaterialTransferType;
use App\Models\Concerns\HasWarehouse;
use Illuminate\Database\Eloquent\Model;

/**
 * Bahan tambahan untuk SPK (BBT) atau retur sisa bahan SPK ke gudang (RSB).
 */
class ProductionMaterialTransfer extends Model
{
    use HasWarehouse;

    protected $guarded = ['id'];

    protected $casts = [
        'type' => ProductionMaterialTransferType::class,
        'date' => 'date',
    ];

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function items()
    {
        return $this->hasMany(ProductionMaterialTransferItem::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function totalValue(): float
    {
        return round($this->items->sum(fn ($i) => (float) $i->quantity * (float) $i->unit_cost), 2);
    }
}
