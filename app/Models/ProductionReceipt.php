<?php

namespace App\Models;

use App\Models\Concerns\HasWarehouse;
use Illuminate\Database\Eloquent\Model;

/**
 * Setoran hasil produksi (HPJ): barang jadi bagus masuk stok jual, reject grade B masuk stok cacat.
 * Bisa bertahap selama SPK berjalan; setoran penutup (is_final) dibuat saat SPK diselesaikan.
 */
class ProductionReceipt extends Model
{
    use HasWarehouse;

    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'date',
        'is_final' => 'boolean',
        'quantity' => 'decimal:2',
        'defect_quantity' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
    ];

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function items()
    {
        return $this->hasMany(ProductionReceiptItem::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function goodValue(): float
    {
        return round((float) $this->quantity * (float) $this->unit_cost, 2);
    }

    public function defectValue(): float
    {
        return round((float) $this->total_cost - $this->goodValue(), 2);
    }
}
