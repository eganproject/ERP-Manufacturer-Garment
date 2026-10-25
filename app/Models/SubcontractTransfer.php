<?php

namespace App\Models;

use App\Enums\SubcontractTransferType;
use App\Models\Concerns\HasWarehouse;
use Illuminate\Database\Eloquent\Model;

/**
 * Surat jalan bahan ke vendor maklon (KBM) atau bukti retur sisa bahan (RBM).
 */
class SubcontractTransfer extends Model
{
    use HasWarehouse;

    protected $guarded = ['id'];

    protected $casts = [
        'type' => SubcontractTransferType::class,
        'date' => 'date',
    ];

    public function subcontractOrder()
    {
        return $this->belongsTo(SubcontractOrder::class);
    }

    public function items()
    {
        return $this->hasMany(SubcontractTransferItem::class);
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
