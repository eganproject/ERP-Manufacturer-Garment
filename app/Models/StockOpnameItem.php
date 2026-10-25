<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockOpnameItem extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'system_quantity' => 'decimal:2',
        'counted_quantity' => 'decimal:2',
        'difference' => 'decimal:2',
        'unit_cost' => 'decimal:4',
        'value' => 'decimal:2',
    ];

    public function stockable()
    {
        return $this->morphTo();
    }

    public function opname()
    {
        return $this->belongsTo(StockOpname::class, 'stock_opname_id');
    }

    public function isCounted(): bool
    {
        return $this->counted_quantity !== null;
    }
}
