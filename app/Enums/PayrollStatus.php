<?php

namespace App\Enums;

/**
 * Status penggajian borongan per periode.
 */
enum PayrollStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Approved => 'Disetujui',
            self::Paid => 'Dibayar',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Approved => 'blue',
            self::Paid => 'green',
            self::Cancelled => 'red',
        };
    }

    /** Status yang mengikat hasil kerja & potongan kasbon. */
    public static function active(): array
    {
        return [self::Draft->value, self::Approved->value, self::Paid->value];
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
