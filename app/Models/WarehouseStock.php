<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Saldo stok satu barang di satu gudang (stok jual & stok cacat). */
class WarehouseStock extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'quantity' => 'decimal:2',
        'defect_quantity' => 'decimal:2',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function stockable()
    {
        return $this->morphTo();
    }
}
