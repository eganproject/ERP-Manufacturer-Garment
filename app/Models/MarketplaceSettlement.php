<?php

namespace App\Models;

use App\Enums\MarketplaceSettlementStatus;
use App\Models\Concerns\HasActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Pencairan dana / settlement marketplace (PCD-): rincian per pesanan beserta potongan platform. */
class MarketplaceSettlement extends Model
{
    use HasActivityLog;

    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'date',
        'status' => MarketplaceSettlementStatus::class,
        'order_amount' => 'decimal:2',
        'voucher' => 'decimal:2',
        'fees' => 'decimal:2',
        'shipping_fee' => 'decimal:2',
        'adjustment' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'receivable' => 'decimal:2',
        'posted_at' => 'datetime',
    ];

    public function store()
    {
        return $this->belongsTo(MarketplaceStore::class, 'marketplace_store_id');
    }

    public function lines()
    {
        return $this->hasMany(MarketplaceSettlementLine::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', MarketplaceSettlementStatus::Posted);
    }

    public function isDraft(): bool
    {
        return $this->status === MarketplaceSettlementStatus::Draft;
    }

    public function isPosted(): bool
    {
        return $this->status === MarketplaceSettlementStatus::Posted;
    }

    /** Selisih yang dibebankan ke akun selisih pencairan = piutang − (dana + voucher + biaya + ongkir). */
    public function difference(): float
    {
        return round((float) $this->receivable - ((float) $this->net_amount + (float) $this->voucher + (float) $this->fees + (float) $this->shipping_fee), 2);
    }
}
