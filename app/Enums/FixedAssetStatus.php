<?php

namespace App\Enums;

enum FixedAssetStatus: string
{
    case Active = 'active';
    case Disposed = 'disposed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktif',
            self::Disposed => 'Dilepas',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Active => 'green',
            self::Disposed => 'gray',
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
