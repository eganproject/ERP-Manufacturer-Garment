<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetDepreciation extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'period' => 'date',
        'amount' => 'decimal:2',
        'accumulated' => 'decimal:2',
        'book_value' => 'decimal:2',
    ];

    public function run()
    {
        return $this->belongsTo(DepreciationRun::class, 'depreciation_run_id');
    }

    public function asset()
    {
        return $this->belongsTo(FixedAsset::class, 'fixed_asset_id');
    }
}
