<?php

namespace App\Support;

use App\Enums\AccountType;
use Illuminate\Support\Facades\DB;

/**
 * Bagan akun standar perusahaan manufaktur garmen (mengacu SAK EP / kebiasaan umum di Indonesia).
 *
 * Akun induk (header) hanya untuk pengelompokan; jurnal diposting ke akun detail.
 * Akun yang dipakai jurnal otomatis ditandai sistem dan dipetakan di {@see LedgerAccounts}.
 */
class ChartOfAccounts
{
    /**
     * [kode, nama, tipe, kode induk, header?, saldo normal khusus (akun kontra)]
     *
     * @return list<array{0: string, 1: string, 2: AccountType, 3: string|null, 4?: bool, 5?: string}>
     */
    public static function definitions(): array
    {
        $a = AccountType::Asset;
        $l = AccountType::Liability;
        $e = AccountType::Equity;
        $r = AccountType::Revenue;
        $c = AccountType::CostOfSales;
        $x = AccountType::Expense;
        $oi = AccountType::OtherIncome;
        $oe = AccountType::OtherExpense;

        return [
            ['1000', 'ASET', $a, null, true],
            ['1100', 'Kas & Setara Kas', $a, '1000', true],
            ['1101', 'Kas', $a, '1100'],
            ['1102', 'Bank', $a, '1100'],
            ['1200', 'Piutang', $a, '1000', true],
            ['1201', 'Piutang Usaha', $a, '1200'],
            ['1202', 'Piutang Nota Debit Supplier (Retur Pembelian)', $a, '1200'],
            ['1209', 'Piutang Lain-lain', $a, '1200'],
            ['1300', 'Persediaan', $a, '1000', true],
            ['1301', 'Persediaan Bahan Baku', $a, '1300'],
            ['1302', 'Persediaan Barang Dalam Proses (WIP)', $a, '1300'],
            ['1303', 'Persediaan Barang Jadi', $a, '1300'],
            ['1304', 'Bahan di Vendor Maklon', $a, '1300'],
            ['1400', 'Pajak & Uang Muka', $a, '1000', true],
            ['1401', 'PPN Masukan', $a, '1400'],
            ['1402', 'Uang Muka Pembelian', $a, '1400'],
            ['1403', 'Biaya Dibayar di Muka', $a, '1400'],
            ['1404', 'PPh Dibayar di Muka', $a, '1400'],
            ['1500', 'Aset Tetap', $a, '1000', true],
            ['1501', 'Tanah', $a, '1500'],
            ['1502', 'Bangunan', $a, '1500'],
            ['1503', 'Mesin & Peralatan Produksi', $a, '1500'],
            ['1504', 'Kendaraan', $a, '1500'],
            ['1505', 'Inventaris Kantor', $a, '1500'],
            ['1509', 'Akumulasi Penyusutan Aset Tetap', $a, '1500', false, 'credit'],

            ['2000', 'KEWAJIBAN', $l, null, true],
            ['2100', 'Kewajiban Jangka Pendek', $l, '2000', true],
            ['2101', 'Hutang Usaha', $l, '2100'],
            ['2102', 'Hutang Barang & Jasa Belum Ditagih', $l, '2100'],
            ['2103', 'Hutang Upah Produksi', $l, '2100'],
            ['2104', 'Biaya Masih Harus Dibayar', $l, '2100'],
            ['2105', 'PPN Keluaran', $l, '2100'],
            ['2106', 'Hutang Pajak Lainnya (PPh)', $l, '2100'],
            ['2107', 'Saldo Nota Kredit Pelanggan (Retur Penjualan)', $l, '2100'],
            ['2108', 'Uang Muka Penjualan', $l, '2100'],
            ['2200', 'Kewajiban Jangka Panjang', $l, '2000', true],
            ['2201', 'Hutang Bank', $l, '2200'],

            ['3000', 'EKUITAS', $e, null, true],
            ['3101', 'Modal Disetor', $e, '3000'],
            ['3102', 'Prive / Dividen', $e, '3000', false, 'debit'],
            ['3201', 'Laba Ditahan', $e, '3000'],
            ['3901', 'Ekuitas Saldo Awal', $e, '3000'],

            ['4000', 'PENDAPATAN', $r, null, true],
            ['4101', 'Penjualan', $r, '4000'],
            ['4102', 'Retur Penjualan', $r, '4000', false, 'debit'],
            ['4103', 'Potongan Penjualan', $r, '4000', false, 'debit'],

            ['5000', 'HARGA POKOK PENJUALAN', $c, null, true],
            ['5101', 'Harga Pokok Penjualan', $c, '5000'],
            ['5102', 'Selisih Harga Pembelian', $c, '5000'],
            ['5103', 'Selisih Biaya Produksi', $c, '5000'],
            ['5104', 'Selisih Persediaan (Stock Opname)', $c, '5000'],
            ['5200', 'Biaya Overhead Pabrik', $c, '5000', true],
            ['5201', 'Listrik & Air Pabrik', $c, '5200'],
            ['5202', 'Penyusutan Mesin & Bangunan Pabrik', $c, '5200'],
            ['5203', 'Pemeliharaan Mesin', $c, '5200'],
            ['5204', 'Bahan Pembantu & Overhead Lain', $c, '5200'],
            ['5209', 'Overhead Pabrik Dibebankan ke Produksi', $c, '5200', false, 'credit'],

            ['6000', 'BEBAN USAHA', $x, null, true],
            ['6101', 'Gaji & Tunjangan Karyawan', $x, '6000'],
            ['6102', 'Sewa', $x, '6000'],
            ['6103', 'Listrik, Air & Telepon Kantor', $x, '6000'],
            ['6104', 'Transportasi & Pengiriman', $x, '6000'],
            ['6105', 'Alat Tulis & Perlengkapan Kantor', $x, '6000'],
            ['6106', 'Pemasaran & Promosi', $x, '6000'],
            ['6107', 'Penyusutan Aset Kantor', $x, '6000'],
            ['6108', 'Perizinan & Profesional', $x, '6000'],
            ['6199', 'Beban Usaha Lain-lain', $x, '6000'],

            ['7000', 'PENDAPATAN LAIN-LAIN', $oi, null, true],
            ['7101', 'Pendapatan Bunga / Jasa Giro', $oi, '7000'],
            ['7199', 'Pendapatan Lain-lain', $oi, '7000'],

            ['8000', 'BEBAN LAIN-LAIN', $oe, null, true],
            ['8101', 'Beban Administrasi Bank', $oe, '8000'],
            ['8102', 'Beban Bunga Pinjaman', $oe, '8000'],
            ['8103', 'Beban Pajak Penghasilan', $oe, '8000'],
            ['8199', 'Beban Lain-lain', $oe, '8000'],
        ];
    }

    /** Isi bagan akun standar (akun yang kodenya sudah ada dilewati). */
    public static function install(): void
    {
        $now = now();
        $system = array_flip(array_column(LedgerAccounts::ROLES, 1));
        $ids = DB::table('accounts')->pluck('id', 'code')->all();

        foreach (self::definitions() as $def) {
            [$code, $name, $type, $parent] = $def;

            if (isset($ids[$code])) {
                continue;
            }

            $ids[$code] = DB::table('accounts')->insertGetId([
                'code' => $code,
                'name' => $name,
                'type' => $type->value,
                'normal_balance' => $def[5] ?? $type->normalBalance(),
                'parent_id' => $parent ? $ids[$parent] : null,
                'is_header' => $def[4] ?? false,
                'is_active' => true,
                'is_system' => isset($system[$code]) || ($def[4] ?? false),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
