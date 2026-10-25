<?php

namespace App\Enums;

/**
 * Jenis biaya konversi produksi di luar bahan baku.
 */
enum ProductionCostType: string
{
    case Labor = 'labor';
    case Cmt = 'cmt';
    case Overhead = 'overhead';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Labor => 'Upah Tenaga Kerja',
            self::Cmt => 'Jasa CMT / Maklon',
            self::Overhead => 'Overhead Pabrik',
            self::Other => 'Biaya Lain',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Labor => 'blue',
            self::Cmt => 'indigo',
            self::Overhead => 'amber',
            self::Other => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $t) => [$t->value => $t->label()])->all();
    }
}
