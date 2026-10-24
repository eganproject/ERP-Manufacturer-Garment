<?php

namespace App\Models;

use App\Enums\ProductionStage as Stage;
use App\Enums\StageStatus;
use Illuminate\Database\Eloquent\Model;

class ProductionStage extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'stage' => Stage::class,
        'status' => StageStatus::class,
        'output_quantity' => 'decimal:2',
        'reject_quantity' => 'decimal:2',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }
}
