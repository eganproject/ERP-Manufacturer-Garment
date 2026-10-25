<?php

namespace App\Models;

use App\Enums\ProductionOrderStatus;
use App\Enums\StageStatus;
use Illuminate\Database\Eloquent\Model;

class ProductionOrder extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'start_date' => 'date',
        'due_date' => 'date',
        'status' => ProductionOrderStatus::class,
        'quantity' => 'decimal:2',
        'finished_quantity' => 'decimal:2',
        'reject_quantity' => 'decimal:2',
        'materials_issued_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function bom()
    {
        return $this->belongsTo(Bom::class);
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function items()
    {
        return $this->hasMany(ProductionOrderItem::class);
    }

    public function materials()
    {
        return $this->hasMany(ProductionMaterial::class);
    }

    public function stages()
    {
        return $this->hasMany(ProductionStage::class)->orderBy('sequence');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEditable(): bool
    {
        return $this->status === ProductionOrderStatus::Draft;
    }

    public function progressPercent(): int
    {
        $stages = $this->relationLoaded('stages') ? $this->stages : $this->stages()->get();

        if ($stages->isEmpty()) {
            return 0;
        }

        return (int) round($stages->where('status', StageStatus::Done)->count() / $stages->count() * 100);
    }
}
