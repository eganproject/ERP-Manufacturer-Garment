<?php

namespace App\Enums;

enum SalesOrderStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Partial = 'partial';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Confirmed => 'Dikonfirmasi',
            self::Partial => 'Terkirim Sebagian',
            self::Delivered => 'Terkirim',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Confirmed => 'blue',
            self::Partial => 'amber',
            self::Delivered => 'green',
            self::Cancelled => 'red',
        };
    }

    /**
     * Status SO yang masih menunggu pengiriman.
     *
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Confirmed, self::Partial];
    }
}
