<?php

namespace App\Enums;

/**
 * Metode penyusutan aset tetap.
 *
 * - Garis lurus: (harga perolehan − nilai sisa) / masa manfaat, sama setiap bulan.
 * - Saldo menurun (ganda): tarif tahunan 2 / masa manfaat (tahun) × nilai buku awal tahun aset,
 *   dibagi 12 per bulan; sisa nilai buku disusutkan habis di bulan terakhir masa manfaat.
 */
enum DepreciationMethod: string
{
    case StraightLine = 'straight_line';
    case DecliningBalance = 'declining_balance';

    public function label(): string
    {
        return match ($this) {
            self::StraightLine => 'Garis Lurus',
            self::DecliningBalance => 'Saldo Menurun',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $m) => [$m->value => $m->label()])->all();
    }
}
