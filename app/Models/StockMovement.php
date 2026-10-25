<?php

namespace App\Models;

use App\Enums\StockLocation;
use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'date',
        'type' => StockMovementType::class,
        'location' => StockLocation::class,
        'quantity' => 'decimal:2',
        'balance' => 'decimal:2',
        'unit_cost' => 'decimal:4',
    ];

    public function stockable()
    {
        return $this->morphTo();
    }

    public function reference()
    {
        return $this->morphTo();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
