<?php

namespace App\Enums;

/**
 * Lokasi stok barang jadi: stok jual (bisa dikirim) atau stok cacat (karantina, tidak bisa dijual).
 */
enum StockLocation: string
{
    case Main = 'main';
    case Defect = 'defect';

    public function label(): string
    {
        return match ($this) {
            self::Main => 'Stok Jual',
            self::Defect => 'Stok Cacat',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Main => 'green',
            self::Defect => 'red',
        };
    }
}
