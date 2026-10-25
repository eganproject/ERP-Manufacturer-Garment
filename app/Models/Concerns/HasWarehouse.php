<?php

namespace App\Models\Concerns;

use App\Models\Warehouse;

/** Dokumen yang menggerakkan stok di satu gudang (warehouse_id). */
trait HasWarehouse
{
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
