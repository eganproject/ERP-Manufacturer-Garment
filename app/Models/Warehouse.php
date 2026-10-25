<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\ValidationException;

/**
 * Gudang / lokasi penyimpanan. Stok per gudang ada di {@see WarehouseStock}; harga pokok rata-rata
 * tetap per barang (berlaku untuk semua gudang).
 */
class Warehouse extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    public function stocks()
    {
        return $this->hasMany(WarehouseStock::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Gudang default (penerimaan/pengiriman bila gudang tidak dipilih). */
    public static function default(): self
    {
        return static::where('is_default', true)->first()
            ?? static::active()->orderBy('id')->first()
            ?? static::create(['code' => 'GU', 'name' => 'Gudang Utama', 'is_default' => true, 'is_active' => true]);
    }

    /** Id gudang dari input form (kosong = gudang default); gudang harus aktif. */
    public static function resolveId(int|string|null $id): int
    {
        if ($id === null || $id === '') {
            return static::default()->id;
        }

        return static::active()->whereKey($id)->value('id')
            ?? throw ValidationException::withMessages(['warehouse_id' => 'Gudang tidak ditemukan atau nonaktif.']);
    }

    public static function rule(): Exists
    {
        return Rule::exists('warehouses', 'id')->where('is_active', true);
    }

    /** @return array<int, string> */
    public static function options(): array
    {
        return static::active()->orderByDesc('is_default')->orderBy('name')->get()
            ->mapWithKeys(fn (self $w) => [$w->id => $w->code.' - '.$w->name])->all();
    }

    /** Ada lebih dari satu gudang (kolom/pilihan gudang baru ditampilkan bila true). */
    public static function isMulti(): bool
    {
        return once(fn () => static::count() > 1);
    }

    public function getDisplayNameAttribute(): string
    {
        return "{$this->code} - {$this->name}";
    }
}
