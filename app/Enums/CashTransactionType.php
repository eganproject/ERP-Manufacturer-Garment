<?php

namespace App\Enums;

/**
 * Jenis bukti kas & bank di luar penerimaan/pembayaran invoice & tagihan.
 */
enum CashTransactionType: string
{
    case In = 'in';
    case Out = 'out';
    case Transfer = 'transfer';

    public function label(): string
    {
        return match ($this) {
            self::In => 'Kas Masuk',
            self::Out => 'Kas Keluar',
            self::Transfer => 'Transfer Antar Rekening',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::In => 'green',
            self::Out => 'red',
            self::Transfer => 'blue',
        };
    }

    public function prefix(): string
    {
        return match ($this) {
            self::In => 'KM',
            self::Out => 'KK',
            self::Transfer => 'TRF',
        };
    }

    /** Petunjuk singkat di form. */
    public function hint(): string
    {
        return match ($this) {
            self::In => 'Uang masuk selain pelunasan invoice: setoran modal, pinjaman bank, bunga/jasa giro, penjualan aset, pendapatan lain.',
            self::Out => 'Uang keluar selain pembayaran tagihan supplier & upah: biaya operasional (listrik, sewa, transport), pembelian aset, angsuran pinjaman, prive.',
            self::Transfer => 'Pindah dana antar rekening sendiri, mis. setor tunai ke bank atau tarik tunai untuk kas kecil.',
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
