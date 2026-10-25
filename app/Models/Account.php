<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Enums\CashFlowActivity;
use App\Support\LedgerAccounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class Account extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'type' => AccountType::class,
        'is_header' => 'boolean',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
        'is_cash' => 'boolean',
    ];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function lines()
    {
        return $this->hasMany(JournalLine::class);
    }

    /** Akun detail aktif yang boleh dipakai di jurnal. */
    public function scopePostable(Builder $query): Builder
    {
        return $query->where('is_header', false)->where('is_active', true);
    }

    /** Rekening kas & bank aktif (dipakai transaksi kas, pembayaran, arus kas). */
    public function scopeCash(Builder $query): Builder
    {
        return $query->where('is_cash', true)->where('is_header', false);
    }

    /**
     * Pilihan rekening kas & bank untuk form.
     *
     * @return array<int, string>
     */
    public static function cashOptions(): array
    {
        return static::cash()->where('is_active', true)->orderBy('code')->get()
            ->mapWithKeys(fn (self $a) => [$a->id => $a->display_name.($a->bank_account_number ? ' ('.$a->bank_account_number.')' : '')])
            ->all();
    }

    /** Aturan validasi: id rekening kas & bank aktif. */
    public static function cashRule(): Exists
    {
        return Rule::exists('accounts', 'id')->where('is_cash', true)->where('is_header', false)->where('is_active', true);
    }

    /**
     * Kelompok arus kas akun ini. Bila tidak diatur: aset tetap (15xx, kecuali akumulasi penyusutan) = investasi;
     * hutang jangka panjang (22xx) dan ekuitas (kecuali ekuitas saldo awal stok) = pendanaan; selain itu operasi.
     */
    public function cashFlowActivity(): CashFlowActivity
    {
        return CashFlowActivity::tryFrom((string) $this->cash_flow_activity) ?? $this->defaultCashFlowActivity();
    }

    public function defaultCashFlowActivity(): CashFlowActivity
    {
        $parentCode = (string) $this->parent?->code;
        $in = fn (string $prefix) => str_starts_with($this->code, $prefix) || str_starts_with($parentCode, $prefix);

        return match (true) {
            $this->type === AccountType::Asset && $in('15') && $this->isDebitNormal() => CashFlowActivity::Investing,
            $this->type === AccountType::Liability && $in('22') => CashFlowActivity::Financing,
            $this->type === AccountType::Equity && $this->code !== LedgerAccounts::code('opening_equity') => CashFlowActivity::Financing,
            default => CashFlowActivity::Operating,
        };
    }

    public function isDebitNormal(): bool
    {
        return $this->normal_balance === 'debit';
    }

    /** Saldo bertanda sesuai saldo normal akun. */
    public function signedBalance(float $debit, float $credit): float
    {
        return round($this->isDebitNormal() ? $debit - $credit : $credit - $debit, 2);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->code.' · '.$this->name;
    }

    public function depth(): int
    {
        $depth = 0;
        $parent = $this->parent;

        while ($parent && $depth < 5) {
            $depth++;
            $parent = $parent->parent;
        }

        return $depth;
    }

    public function canDelete(): bool
    {
        return ! $this->is_system && $this->lines()->doesntExist() && $this->children()->doesntExist();
    }

    /**
     * Pilihan akun untuk form (akun detail aktif).
     *
     * @return array<int, string>
     */
    public static function options(): array
    {
        return static::postable()->orderBy('code')->get()->mapWithKeys(fn (self $a) => [$a->id => $a->display_name])->all();
    }
}
