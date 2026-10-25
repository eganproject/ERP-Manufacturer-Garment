<?php

namespace App\Models;

use App\Enums\MarketplaceClaimStatus;
use App\Enums\MarketplaceOrderStatus;
use App\Enums\MarketplaceReturnCondition;
use App\Models\Concerns\HasActivityLog;
use Illuminate\Database\Eloquent\Model;

/** Pesanan TikTok Shop / Shopee (nomor pesanan platform unik per toko). */
class MarketplaceOrder extends Model
{
    use HasActivityLog;

    protected $guarded = ['id'];

    protected $casts = [
        'status' => MarketplaceOrderStatus::class,
        'order_date' => 'datetime',
        'paid_at' => 'datetime',
        'shipped_at' => 'date',
        'completed_at' => 'date',
        'cancelled_at' => 'date',
        'returned_at' => 'date',
        'buyer_shipping_fee' => 'decimal:2',
        'total' => 'decimal:2',
        'dpp' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'cost' => 'decimal:2',
        'returned_total' => 'decimal:2',
        'returned_dpp' => 'decimal:2',
        'returned_tax' => 'decimal:2',
        'returned_cost' => 'decimal:2',
        'return_condition' => MarketplaceReturnCondition::class,
        'returned_lost_cost' => 'decimal:2',
        'claim_status' => MarketplaceClaimStatus::class,
        'claim_amount' => 'decimal:2',
        'claim_resolved_at' => 'date',
        'settled_receivable' => 'decimal:2',
        'settled_net' => 'decimal:2',
    ];

    public function store()
    {
        return $this->belongsTo(MarketplaceStore::class, 'marketplace_store_id');
    }

    public function items()
    {
        return $this->hasMany(MarketplaceOrderItem::class);
    }

    public function settlementLines()
    {
        return $this->hasMany(MarketplaceSettlementLine::class);
    }

    /** Dipakai jurnal & log sebagai nomor dokumen. */
    public function getNumberAttribute(): string
    {
        return $this->order_number;
    }

    public function isShipped(): bool
    {
        return $this->status->isShipped();
    }

    public function hasUnmappedItems(): bool
    {
        return $this->items->contains(fn ($i) => ! $i->product_variant_id);
    }

    /** Piutang marketplace yang belum dilunasi pencairan (total − retur + kompensasi klaim − sudah cair). Negatif = kelebihan cair. */
    public function outstandingReceivable(): float
    {
        if (! $this->isShipped()) {
            return 0.0;
        }

        return round((float) $this->total - (float) $this->returned_total + $this->claimCompensation() - (float) $this->settled_receivable, 2);
    }

    /** Kompensasi banding yang disetujui platform (menambah piutang marketplace). */
    public function claimCompensation(): float
    {
        return $this->claim_status === MarketplaceClaimStatus::Won ? (float) $this->claim_amount : 0.0;
    }

    /** Retur bermasalah yang bisa diajukan banding: ada barang hilang / tertukar / cacat dan belum pernah diajukan. */
    public function canOpenClaim(): bool
    {
        return $this->returned_at !== null && $this->claim_status === null
            && ((float) $this->returned_lost_cost > 0 || $this->return_condition?->claimable());
    }

    public function netSales(): float
    {
        return round((float) $this->dpp - (float) $this->returned_dpp, 2);
    }

    public function netCost(): float
    {
        return round((float) $this->cost - (float) $this->returned_cost, 2);
    }

    public function isSettled(): bool
    {
        return $this->settlementLines()->whereHas('settlement', fn ($q) => $q->where('status', 'posted'))->exists();
    }
}
