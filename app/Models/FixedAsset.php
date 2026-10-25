<?php

namespace App\Models;

use App\Enums\AssetAcquisitionType;
use App\Enums\AssetDisposalType;
use App\Enums\DepreciationMethod;
use App\Enums\FixedAssetStatus;
use App\Models\Concerns\HasActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Aset tetap (register aset). Nomor AT-. Akun aset/akumulasi/beban disalin dari kategori saat dicatat
 * agar jurnal lama tetap konsisten meski kategori diubah.
 */
class FixedAsset extends Model
{
    use HasActivityLog;

    protected $guarded = ['id'];

    protected $casts = [
        'acquisition_date' => 'date',
        'acquisition_type' => AssetAcquisitionType::class,
        'depreciation_method' => DepreciationMethod::class,
        'depreciation_start' => 'date',
        'disposal_date' => 'date',
        'disposal_type' => AssetDisposalType::class,
        'status' => FixedAssetStatus::class,
        'useful_life_months' => 'integer',
        'cost' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'residual_value' => 'decimal:2',
        'opening_accumulated' => 'decimal:2',
        'accumulated_depreciation' => 'decimal:2',
        'disposal_proceeds' => 'decimal:2',
        'asset_account_id' => 'integer',
        'accumulated_account_id' => 'integer',
        'expense_account_id' => 'integer',
        'cash_account_id' => 'integer',
        'disposal_account_id' => 'integer',
    ];

    public function category()
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function cashAccount()
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }

    public function bill()
    {
        return $this->belongsTo(Bill::class);
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

    public function disposalAccount()
    {
        return $this->belongsTo(Account::class, 'disposal_account_id');
    }

    public function depreciations()
    {
        return $this->hasMany(AssetDepreciation::class)->orderBy('period');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', FixedAssetStatus::Active);
    }

    public function isActive(): bool
    {
        return $this->status === FixedAssetStatus::Active;
    }

    public function isDepreciable(): bool
    {
        return $this->useful_life_months > 0 && $this->accumulated_account_id && $this->expense_account_id;
    }

    public function bookValue(): float
    {
        return round((float) $this->cost - (float) $this->accumulated_depreciation, 2);
    }

    public function depreciableBase(): float
    {
        return round((float) $this->cost - (float) $this->residual_value, 2);
    }

    public function isFullyDepreciated(): bool
    {
        return $this->isDepreciable() && $this->bookValue() <= (float) $this->residual_value + 0.005;
    }

    /** Bulan pertama masa manfaat: bulan perolehan untuk aset saldo awal, selain itu bulan mulai susut. */
    public function lifeStart(): Carbon
    {
        return $this->acquisition_type === AssetAcquisitionType::Opening
            ? $this->acquisition_date->copy()->startOfMonth()
            : $this->depreciation_start->copy()->startOfMonth();
    }

    /** Bulan terakhir masa manfaat. */
    public function lifeEnd(): ?Carbon
    {
        return $this->useful_life_months > 0 ? $this->lifeStart()->addMonthsNoOverflow($this->useful_life_months - 1) : null;
    }

    public function lastDepreciatedPeriod(): ?Carbon
    {
        $period = $this->depreciations()->max('period');

        return $period ? Carbon::parse($period)->startOfMonth() : null;
    }

    public function canEditDepreciation(): bool
    {
        return $this->isActive() && ! $this->depreciations()->exists();
    }

    public function canCancel(): bool
    {
        return $this->isActive() && ! $this->depreciations()->exists()
            && (! $this->bill || $this->bill->canCancel());
    }

    /** Laba (+) / rugi (−) pelepasan. */
    public function disposalGain(): float
    {
        return round((float) $this->disposal_proceeds - $this->bookValue(), 2);
    }

    public static function formatLife(int $months): string
    {
        if ($months <= 0) {
            return 'Tidak disusutkan';
        }

        $years = intdiv($months, 12);
        $rest = $months % 12;

        return trim(($years ? "{$years} tahun " : '').($rest ? "{$rest} bulan" : ''));
    }

    public function lifeLabel(): string
    {
        return self::formatLife($this->useful_life_months);
    }
}
