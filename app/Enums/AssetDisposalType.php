<?php

namespace App\Enums;

enum AssetDisposalType: string
{
    case Sold = 'sold';
    case Scrapped = 'scrapped';

    public function label(): string
    {
        return match ($this) {
            self::Sold => 'Dijual',
            self::Scrapped => 'Dihapus / Rusak',
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
