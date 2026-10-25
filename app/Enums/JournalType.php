<?php

namespace App\Enums;

enum JournalType: string
{
    case Auto = 'auto';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Otomatis',
            self::Manual => 'Manual',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Auto => 'gray',
            self::Manual => 'indigo',
        };
    }

    public function prefix(): string
    {
        return match ($this) {
            self::Auto => 'JO',
            self::Manual => 'JU',
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
