<?php

namespace App\Enums;

/**
 * Perpindahan bahan internal SPK selama produksi: bahan tambahan dari gudang atau retur sisa bahan ke gudang.
 */
enum ProductionMaterialTransferType: string
{
    case Issue = 'issue';
    case Return = 'return';

    public function label(): string
    {
        return match ($this) {
            self::Issue => 'Bahan Tambahan',
            self::Return => 'Retur Sisa Bahan',
        };
    }

    public function documentTitle(): string
    {
        return match ($this) {
            self::Issue => 'BUKTI PENGELUARAN BAHAN TAMBAHAN',
            self::Return => 'BUKTI RETUR SISA BAHAN PRODUKSI',
        };
    }

    public function prefix(): string
    {
        return match ($this) {
            self::Issue => 'BBT',
            self::Return => 'RSB',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Issue => 'indigo',
            self::Return => 'amber',
        };
    }
}
