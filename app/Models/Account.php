<?php

namespace App\Models;

use App\Enums\AccountType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'type' => AccountType::class,
        'is_header' => 'boolean',
        'is_active' => 'boolean',
        'is_system' => 'boolean',
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
