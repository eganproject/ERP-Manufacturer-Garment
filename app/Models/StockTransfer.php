<?php

namespace App\Models;

use App\Enums\ReturnStatus;
use App\Models\Concerns\HasActivityLog;
use Illuminate\Database\Eloquent\Model;

/** Transfer stok antar gudang (TG-). Nilai persediaan tidak berubah, hanya lokasinya. */
class StockTransfer extends Model
{
    use HasActivityLog;

    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'date',
        'status' => ReturnStatus::class,
    ];

    public function fromWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function items()
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCancelled(): bool
    {
        return $this->status === ReturnStatus::Cancelled;
    }

    public function totalValue(): float
    {
        return round($this->items->sum(fn ($i) => (float) $i->quantity * (float) $i->unit_cost), 2);
    }
}
