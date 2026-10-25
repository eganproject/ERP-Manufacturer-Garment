<?php

namespace App\Enums;

enum SalesOrderStatus: string
{
    case Draft = 'draft';
    case Confirmed = 'confirmed';
    case Partial = 'partial';
    case Delivered = 'delivered';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Confirmed => 'Dikonfirmasi',
            self::Partial => 'Terkirim Sebagian',
            self::Delivered => 'Terkirim',
            self::Closed => 'Ditutup',
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
            self::Closed => 'indigo',
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
