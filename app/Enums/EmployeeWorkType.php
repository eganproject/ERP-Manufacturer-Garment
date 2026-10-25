<?php

namespace App\Enums;

/**
 * Pola kerja karyawan produksi borongan.
 */
enum EmployeeWorkType: string
{
    case Full = 'full';
    case Operation = 'operation';
    case Mixed = 'mixed';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'Per pcs jadi (komplit)',
            self::Operation => 'Per segmen / operasi',
            self::Mixed => 'Keduanya',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Full => 'indigo',
            self::Operation => 'blue',
            self::Mixed => 'gray',
        };
    }

    public function allowsFull(): bool
    {
        return $this !== self::Operation;
    }

    public function allowsOperation(): bool
    {
        return $this !== self::Full;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $t) => [$t->value => $t->label()])->all();
    }
}
