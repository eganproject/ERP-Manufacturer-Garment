<?php

namespace App\Enums;

/**
 * Tahap produksi garmen, berurutan sesuai alur umum. Tiap artikel memilih tahap yang dipakai
 * (products.stage_route); tahap tambahan seperti sablon, bordir dan cuci bisa diaktifkan per artikel.
 */
enum ProductionStage: string
{
    case Cutting = 'cutting';
    case Printing = 'printing';
    case Embroidery = 'embroidery';
    case Sewing = 'sewing';
    case Washing = 'washing';
    case Finishing = 'finishing';
    case QualityControl = 'qc';
    case Packing = 'packing';

    public function label(): string
    {
        return match ($this) {
            self::Cutting => 'Cutting (Potong)',
            self::Printing => 'Sablon / Printing',
            self::Embroidery => 'Bordir',
            self::Sewing => 'Sewing (Jahit)',
            self::Washing => 'Cuci / Washing',
            self::Finishing => 'Finishing',
            self::QualityControl => 'Quality Control',
            self::Packing => 'Packing',
        };
    }

    /** Tahap bawaan untuk artikel yang belum mengatur alur tahapnya. */
    public static function standard(): array
    {
        return [self::Cutting, self::Sewing, self::Finishing, self::QualityControl, self::Packing];
    }

    /** Tahap tambahan opsional (sering dikerjakan vendor luar). */
    public function isOptional(): bool
    {
        return ! in_array($this, self::standard(), true);
    }

    /**
     * Urutkan daftar tahap sesuai alur produksi (urutan case).
     *
     * @param  iterable<self|string>  $stages
     * @return list<self>
     */
    public static function ordered(iterable $stages): array
    {
        $values = collect($stages)->map(fn ($s) => $s instanceof self ? $s->value : (string) $s)->all();

        return array_values(array_filter(self::cases(), fn (self $s) => in_array($s->value, $values, true)));
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
