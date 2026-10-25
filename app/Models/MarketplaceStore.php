<?php

namespace App\Models;

use App\Enums\MarketplacePlatform;
use App\Models\Concerns\HasActivityLog;
use App\Support\LedgerAccounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Toko di TikTok Shop / Shopee. Stok dialokasikan dari gudang toko, dana pencairan masuk ke
 * rekening saldo penjual (akun kas/bank), piutang marketplace dirinci per toko di jurnal.
 */
class MarketplaceStore extends Model
{
    use HasActivityLog;

    protected $guarded = ['id'];

    protected $casts = [
        'platform' => MarketplacePlatform::class,
        'tax_rate' => 'decimal:2',
        'is_active' => 'boolean',
        'api_enabled' => 'boolean',
        'api_partner_key' => 'encrypted',
        'api_access_token' => 'encrypted',
        'api_refresh_token' => 'encrypted',
        'api_token_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    protected $hidden = ['api_partner_key', 'api_access_token', 'api_refresh_token'];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function balanceAccount()
    {
        return $this->belongsTo(Account::class, 'balance_account_id');
    }

    public function orders()
    {
        return $this->hasMany(MarketplaceOrder::class);
    }

    public function mappings()
    {
        return $this->hasMany(MarketplaceProduct::class);
    }

    public function settlements()
    {
        return $this->hasMany(MarketplaceSettlement::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Akun saldo penjual untuk jurnal pencairan (kosong = peran marketplace_balance). */
    public function balanceAccountRole(): int|string
    {
        return $this->balance_account_id ?: 'marketplace_balance';
    }

    public function balanceAccountName(): string
    {
        return $this->balanceAccount?->display_name ?? app(LedgerAccounts::class)->account('marketplace_balance')->display_name;
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->platform->label().' · '.$this->name;
    }

    public function hasApiCredentials(): bool
    {
        return $this->api_enabled && $this->api_partner_id && $this->api_partner_key && $this->api_access_token && $this->shop_id;
    }

    /** @return array<int, string> */
    public static function options(bool $activeOnly = true): array
    {
        return static::query()->when($activeOnly, fn ($q) => $q->active())->orderBy('platform')->orderBy('name')->get()
            ->mapWithKeys(fn (self $s) => [$s->id => $s->display_name])->all();
    }
}
