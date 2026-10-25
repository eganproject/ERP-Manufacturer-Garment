<?php

namespace App\Models;

use App\Enums\DepreciationMethod;
use Illuminate\Database\Eloquent\Model;

/**
 * Kategori aset tetap: metode & masa manfaat default serta akun aset, akumulasi penyusutan, dan beban penyusutan.
 */
class AssetCategory extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'depreciation_method' => DepreciationMethod::class,
        'useful_life_months' => 'integer',
        'residual_rate' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function assets()
    {
        return $this->hasMany(FixedAsset::class);
    }

    public function assetAccount()
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }

    public function accumulatedAccount()
    {
        return $this->belongsTo(Account::class, 'accumulated_account_id');
    }

    public function expenseAccount()
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }

    public function isDepreciable(): bool
    {
        return $this->useful_life_months > 0;
    }

    public function lifeLabel(): string
    {
        return FixedAsset::formatLife($this->useful_life_months);
    }
}
