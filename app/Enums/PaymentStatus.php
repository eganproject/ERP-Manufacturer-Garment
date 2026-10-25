<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Posted = 'posted';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Posted => 'Tercatat',
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
}
