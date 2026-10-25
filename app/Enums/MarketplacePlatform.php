<?php

namespace App\Enums;

/** Platform marketplace yang didukung (hanya TikTok Shop & Shopee). */
enum MarketplacePlatform: string
{
    case Shopee = 'shopee';
    case TikTok = 'tiktok';

    public function label(): string
    {
        return match ($this) {
            self::Shopee => 'Shopee',
            self::TikTok => 'TikTok Shop',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Shopee => 'amber',
            self::TikTok => 'gray',
        };
    }

    /** Format tanggal bertanda garis miring pada file ekspor: Shopee d/m/Y, TikTok m/d/Y. */
    public function slashDateFormat(): string
    {
        return $this === self::TikTok ? 'mdy' : 'dmy';
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $p) => [$p->value => $p->label()])->all();
    }
}
