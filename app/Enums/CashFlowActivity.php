<?php

namespace App\Enums;

/**
 * Kelompok aktivitas laporan arus kas (PSAK 2 / SAK EP).
 */
enum CashFlowActivity: string
{
    case Operating = 'operating';
    case Investing = 'investing';
    case Financing = 'financing';

    public function label(): string
    {
        return match ($this) {
            self::Operating => 'Aktivitas Operasi',
            self::Investing => 'Aktivitas Investasi',
            self::Financing => 'Aktivitas Pendanaan',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $a) => [$a->value => $a->label()])->all();
    }
}
