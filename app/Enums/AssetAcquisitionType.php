<?php

namespace App\Enums;

/**
 * Cara perolehan aset tetap (menentukan akun lawan jurnal perolehan).
 */
enum AssetAcquisitionType: string
{
    case Cash = 'cash';
    case Credit = 'credit';
    case Opening = 'opening';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'Tunai / Transfer',
            self::Credit => 'Kredit (Tagihan Supplier)',
            self::Opening => 'Saldo Awal (aset lama)',
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Cash => 'Dibayar langsung dari rekening kas/bank: Dr Aset / Cr Kas-Bank.',
            self::Credit => 'Dibeli dengan tempo: otomatis dibuatkan tagihan supplier (hutang usaha) yang dibayar lewat Pembayaran Supplier.',
            self::Opening => 'Aset yang sudah dimiliki sebelum memakai sistem: lawan jurnal Ekuitas Saldo Awal, bisa dengan akumulasi penyusutan awal.',
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
