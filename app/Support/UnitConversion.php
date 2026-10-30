<?php

namespace App\Support;

use App\Models\Unit;

/**
 * Konversi baku antar satuan (dicocokkan dari kode/nama satuan), dipakai untuk mengisi otomatis
 * faktor konversi satuan beli → satuan stok. Satuan yang isinya beda-beda (ROLL, BAL) tetap diisi manual.
 */
class UnitConversion
{
    /** Satuan dasar per kelompok: [alias => [kelompok, nilai dalam satuan dasar]]. */
    private const UNITS = [
        // Panjang (meter)
        'm' => ['length', 1], 'mtr' => ['length', 1], 'meter' => ['length', 1],
        'cm' => ['length', 0.01], 'centimeter' => ['length', 0.01], 'mm' => ['length', 0.001],
        'yd' => ['length', 0.9144], 'yds' => ['length', 0.9144], 'yard' => ['length', 0.9144],
        'in' => ['length', 0.0254], 'inch' => ['length', 0.0254], 'inci' => ['length', 0.0254],
        'ft' => ['length', 0.3048], 'feet' => ['length', 0.3048], 'kaki' => ['length', 0.3048],
        // Berat (kg)
        'kg' => ['mass', 1], 'kilogram' => ['mass', 1], 'g' => ['mass', 0.001], 'gr' => ['mass', 0.001], 'gram' => ['mass', 0.001],
        'ons' => ['mass', 0.1], 'lb' => ['mass', 0.45359237], 'lbs' => ['mass', 0.45359237], 'pound' => ['mass', 0.45359237],
        'ton' => ['mass', 1000],
        // Jumlah (pcs)
        'pcs' => ['count', 1], 'pc' => ['count', 1], 'pieces' => ['count', 1], 'buah' => ['count', 1], 'biji' => ['count', 1],
        'lusin' => ['count', 12], 'lsn' => ['count', 12], 'dz' => ['count', 12], 'dozen' => ['count', 12],
        'kodi' => ['count', 20], 'gross' => ['count', 144], 'gros' => ['count', 144], 'rim' => ['count', 500],
    ];

    /** Faktor baku: 1 $from = ? $to. Null bila tidak sekelompok / tidak dikenal. */
    public static function factor(?string $from, ?string $to): ?float
    {
        $a = self::UNITS[self::key($from)] ?? null;
        $b = self::UNITS[self::key($to)] ?? null;

        if (! $a || ! $b || $a[0] !== $b[0]) {
            return null;
        }

        return round($a[1] / $b[1], 6);
    }

    /**
     * Peta faktor baku antar satuan terdaftar untuk JavaScript: "fromId:toId" => faktor.
     *
     * @param  iterable<Unit>  $units
     * @return array<string, float>
     */
    public static function mapFor(iterable $units): array
    {
        $map = [];
        foreach ($units as $from) {
            foreach ($units as $to) {
                if ($from->id !== $to->id && ($f = self::factor($from->code, $to->code) ?? self::factor($from->name, $to->name)) !== null) {
                    $map["{$from->id}:{$to->id}"] = $f;
                }
            }
        }

        return $map;
    }

    /** Faktor untuk tampilan tanpa nol berlebih, mis. 0,9144. */
    public static function format(float $factor): string
    {
        return rtrim(rtrim(number_format($factor, 6, ',', '.'), '0'), ',');
    }

    private static function key(?string $unit): string
    {
        return rtrim(mb_strtolower(trim((string) $unit)), '.');
    }
}
