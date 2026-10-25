<?php

namespace App\Enums;

/**
 * Status retur penjualan / pembelian.
 */
enum ReturnStatus: string
{
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Posted => 'Diposting',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Posted => 'green',
            self::Cancelled => 'red',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
