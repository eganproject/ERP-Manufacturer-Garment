<?php

namespace App\Models;

use App\Enums\ProductionCostType;
use Illuminate\Database\Eloquent\Model;

class ProductionCost extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'type' => ProductionCostType::class,
        'amount' => 'decimal:2',
        'date' => 'date',
    ];

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function subcontractReceipt()
    {
        return $this->belongsTo(SubcontractReceipt::class);
    }

    public function pieceworkSheet()
    {
        return $this->belongsTo(PieceworkSheet::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
