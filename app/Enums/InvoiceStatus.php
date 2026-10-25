<?php

namespace App\Enums;

/**
 * Status pembayaran invoice penjualan maupun tagihan supplier.
 */
enum InvoiceStatus: string
{
    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Belum Dibayar',
            self::Partial => 'Dibayar Sebagian',
            self::Paid => 'Lunas',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Unpaid => 'blue',
            self::Partial => 'amber',
            self::Paid => 'green',
            self::Cancelled => 'red',
        };
    }

    /**
     * Status yang masih memiliki sisa tagihan.
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Unpaid, self::Partial];
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
