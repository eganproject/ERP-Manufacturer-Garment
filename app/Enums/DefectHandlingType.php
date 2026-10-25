<?php

namespace App\Enums;

/**
 * Penanganan barang cacat (dokumen BC-).
 */
enum DefectHandlingType: string
{
    case Quarantine = 'quarantine';
    case Rework = 'rework';
    case Restock = 'restock';
    case Scrap = 'scrap';

    public function label(): string
    {
        return match ($this) {
            self::Quarantine => 'Pindah ke Stok Cacat',
            self::Rework => 'Perbaiki (Rework)',
            self::Restock => 'Kembalikan ke Stok Jual',
            self::Scrap => 'Musnahkan (Scrap)',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Quarantine => 'amber',
            self::Rework => 'indigo',
            self::Restock => 'green',
            self::Scrap => 'red',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Quarantine => 'Barang jadi di gudang yang ternyata cacat dipindah dari stok jual ke stok cacat agar tidak ikut terkirim.',
            self::Rework => 'Barang cacat diperbaiki lalu masuk lagi ke stok jual. Biaya perbaikan (bila ada) menambah HPP barang.',
            self::Restock => 'Barang ternyata layak jual (cacat ringan / salah klaim) dikembalikan ke stok jual tanpa biaya.',
            self::Scrap => 'Barang tidak bisa diperbaiki dimusnahkan; nilainya dibebankan ke Beban Barang Cacat.',
        };
    }

    /** Barang diambil dari stok cacat (selain Pindah ke Stok Cacat yang mengambil dari stok jual). */
    public function fromDefect(): bool
    {
        return $this !== self::Quarantine;
    }

    public function stockMovementType(): StockMovementType
    {
        return match ($this) {
            self::Quarantine => StockMovementType::DefectQuarantine,
            self::Rework => StockMovementType::DefectRework,
            self::Restock => StockMovementType::DefectRestock,
            self::Scrap => StockMovementType::DefectScrap,
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
