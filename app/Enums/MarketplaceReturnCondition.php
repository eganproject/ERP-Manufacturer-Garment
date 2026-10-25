<?php

namespace App\Enums;

/** Kondisi paket retur marketplace saat dibuka gudang (sebaiknya direkam video unboxing sebagai bukti banding). */
enum MarketplaceReturnCondition: string
{
    case Good = 'good';
    case Defect = 'defect';
    case WrongItem = 'wrong_item';
    case Empty = 'empty';
    case NotReceived = 'not_received';
    case RefundOnly = 'refund_only';

    public function label(): string
    {
        return match ($this) {
            self::Good => 'Sesuai, layak jual',
            self::Defect => 'Barang kita, rusak / cacat',
            self::WrongItem => 'Bukan barang kita / tertukar',
            self::Empty => 'Paket kosong / isi kurang',
            self::NotReceived => 'Paket retur tidak sampai',
            self::RefundOnly => 'Refund tanpa pengembalian barang',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Good => 'green',
            self::Defect => 'amber',
            self::RefundOnly => 'gray',
            default => 'red',
        };
    }

    /** Kondisi yang layak diajukan banding / klaim ke platform. */
    public function claimable(): bool
    {
        return in_array($this, [self::WrongItem, self::Empty, self::NotReceived, self::Defect], true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }
}
