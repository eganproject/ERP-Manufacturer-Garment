<?php

namespace App\Services\Import;

use App\Models\Category;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Bantuan bersama importer: isi model (sel kosong = nilai lama), cari / buat satuan & kategori.
 */
trait Concerns
{
    /**
     * Data baru: semua kolom (null → default). Data lama: hanya kolom yang diisi.
     *
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $defaults
     */
    protected function upsert(Model $model, array $values, array $defaults = []): string
    {
        $exists = $model->exists;
        foreach ($values as $key => $value) {
            if ($value !== null) {
                $model->setAttribute($key, $value);
            } elseif (! $exists && array_key_exists($key, $defaults)) {
                $model->setAttribute($key, $defaults[$key]);
            }
        }
        foreach ($defaults as $key => $value) {
            if (! $exists && $model->getAttribute($key) === null) {
                $model->setAttribute($key, $value);
            }
        }
        $model->save();

        return $exists ? 'updated' : 'created';
    }

    /** Satuan menurut kode atau nama; dibuat otomatis bila belum ada. */
    protected function unitId(?string $text): ?int
    {
        if ($text === null || $text === '') {
            return null;
        }

        $unit = Unit::whereRaw('lower(code) = ?', [mb_strtolower($text)])->first()
            ?? Unit::whereRaw('lower(name) = ?', [mb_strtolower($text)])->first();

        if (! $unit) {
            $code = Str::upper(Str::limit(preg_replace('/[^A-Za-z0-9]/', '', Str::ascii($text)) ?: 'SAT', 16, ''));
            $base = $code;
            for ($i = 2; Unit::where('code', $code)->exists(); $i++) {
                $code = $base.$i;
            }
            $unit = Unit::create(['code' => $code, 'name' => Str::limit($text, 100, '')]);
        }

        return $unit->id;
    }

    /** Kategori menurut nama untuk jenis material / product; dibuat otomatis bila belum ada. */
    protected function categoryId(?string $name, string $type): ?int
    {
        if ($name === null || $name === '') {
            return null;
        }

        $category = Category::ofType($type)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
            ?? Category::create(['name' => Str::limit($name, 100, ''), 'type' => $type]);

        return $category->id;
    }

    protected static function yesNo(?bool $value): string
    {
        return $value ? 'Ya' : 'Tidak';
    }

    protected static function num(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
