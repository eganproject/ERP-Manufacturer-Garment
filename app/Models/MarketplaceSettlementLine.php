<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplaceSettlementLine extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'order_amount' => 'decimal:2',
        'voucher' => 'decimal:2',
        'commission' => 'decimal:2',
        'service_fee' => 'decimal:2',
        'transaction_fee' => 'decimal:2',
        'shipping_fee' => 'decimal:2',
        'other_fee' => 'decimal:2',
        'adjustment' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'receivable' => 'decimal:2',
    ];

    public function settlement()
    {
        return $this->belongsTo(MarketplaceSettlement::class, 'marketplace_settlement_id');
    }

    public function order()
    {
        return $this->belongsTo(MarketplaceOrder::class, 'marketplace_order_id');
    }

    /** Komisi + biaya layanan + biaya transaksi + biaya lain. */
    public function fees(): float
    {
        return round((float) $this->commission + (float) $this->service_fee + (float) $this->transaction_fee + (float) $this->other_fee, 2);
    }
}
