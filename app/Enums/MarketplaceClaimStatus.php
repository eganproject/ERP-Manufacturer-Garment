<?php

namespace App\Enums;

/** Banding / klaim ke Shopee / TikTok Shop atas retur bermasalah. */
enum MarketplaceClaimStatus: string
{
    case Open = 'open';
    case Won = 'won';
    case Lost = 'lost';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Banding Diajukan',
            self::Won => 'Banding Diterima',
            self::Lost => 'Banding Ditolak',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Open => 'amber',
            self::Won => 'green',
            self::Lost => 'red',
        };
    }
}
