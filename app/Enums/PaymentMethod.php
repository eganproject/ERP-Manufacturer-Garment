<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Transfer = 'transfer';
    case Cash = 'cash';
    case Giro = 'giro';
    case Cheque = 'cheque';

    public function label(): string
    {
        return match ($this) {
            self::Transfer => 'Transfer Bank',
            self::Cash => 'Tunai',
            self::Giro => 'Bilyet Giro',
            self::Cheque => 'Cek',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $m) => [$m->value => $m->label()])->all();
    }
}
