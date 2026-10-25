<?php

namespace App\Models;

use App\Enums\ReturnStatus;
use App\Models\Concerns\HasActivityLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Posting penyusutan aset tetap satu bulan (PNY-), satu jurnal untuk semua aset.
 * Bulan yang terlewat (mis. aset dicatat mundur) ikut disusulkan di posting berikutnya.
 */
class DepreciationRun extends Model
{
    use HasActivityLog;

    protected $guarded = ['id'];

    protected $casts = [
        'period' => 'date',
        'date' => 'date',
        'status' => ReturnStatus::class,
        'total' => 'decimal:2',
        'asset_count' => 'integer',
    ];

    public function lines()
    {
        return $this->hasMany(AssetDepreciation::class)->orderBy('fixed_asset_id')->orderBy('period');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCancelled(): bool
    {
        return $this->status === ReturnStatus::Cancelled;
    }

    public function periodLabel(): string
    {
        return $this->period->translatedFormat('F Y');
    }
}
