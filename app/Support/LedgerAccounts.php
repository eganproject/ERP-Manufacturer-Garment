<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Setting;
use Illuminate\Validation\ValidationException;

/**
 * Pemetaan akun yang dipakai jurnal otomatis. Default memakai kode bagan akun standar,
 * dapat diganti di menu Akuntansi → Pemetaan Akun (disimpan di settings: gl_<peran>).
 */
class LedgerAccounts
{
    /** peran => [keterangan, kode default, grup] */
    public const ROLES = [
        'cash' => ['Kas (pembayaran tunai)', '1101', 'Kas & Bank'],
        'bank' => ['Bank (transfer, giro, cek)', '1102', 'Kas & Bank'],
        'receivable' => ['Piutang usaha', '1201', 'Penjualan'],
        'sales' => ['Penjualan', '4101', 'Penjualan'],
        'sales_discount' => ['Potongan penjualan', '4103', 'Penjualan'],
        'sales_returns' => ['Retur penjualan', '4102', 'Penjualan'],
        'vat_out' => ['PPN keluaran', '2105', 'Penjualan'],
        'customer_credit' => ['Saldo nota kredit pelanggan', '2107', 'Penjualan'],
        'cogs' => ['Harga pokok penjualan', '5101', 'Penjualan'],
        'payable' => ['Hutang usaha', '2101', 'Pembelian'],
        'unbilled_payable' => ['Hutang barang & jasa belum ditagih', '2102', 'Pembelian'],
        'vat_in' => ['PPN masukan', '1401', 'Pembelian'],
        'vat_payable' => ['Hutang PPN (kurang bayar masa PPN)', '2109', 'Pajak'],
        'vat_overpaid' => ['PPN lebih bayar (kompensasi)', '1405', 'Pajak'],
        'supplier_debit' => ['Piutang nota debit supplier', '1202', 'Pembelian'],
        'purchase_variance' => ['Selisih harga pembelian', '5102', 'Pembelian'],
        'raw_material' => ['Persediaan bahan baku', '1301', 'Persediaan & Produksi'],
        'wip' => ['Persediaan barang dalam proses', '1302', 'Persediaan & Produksi'],
        'finished_goods' => ['Persediaan barang jadi', '1303', 'Persediaan & Produksi'],
        'material_at_vendor' => ['Bahan di vendor maklon', '1304', 'Persediaan & Produksi'],
        'wages_payable' => ['Hutang upah produksi (upah dibebankan)', '2103', 'Persediaan & Produksi'],
        'overhead_applied' => ['Overhead pabrik dibebankan', '5209', 'Persediaan & Produksi'],
        'accrued_expense' => ['Biaya masih harus dibayar (biaya produksi lain)', '2104', 'Persediaan & Produksi'],
        'production_variance' => ['Selisih biaya produksi', '5103', 'Persediaan & Produksi'],
        'stock_adjustment' => ['Selisih persediaan (stock opname)', '5104', 'Persediaan & Produksi'],
        'defect_inventory' => ['Persediaan barang cacat (karantina)', '1305', 'Persediaan & Produksi'],
        'defect_writeoff' => ['Beban barang cacat dimusnahkan', '5105', 'Persediaan & Produksi'],
        'employee_advance' => ['Piutang karyawan (kasbon)', '1203', 'Upah Borongan'],
        'wage_allowance' => ['Bonus / tunjangan upah produksi', '5205', 'Upah Borongan'],
        'payroll_other_income' => ['Potongan upah lain (denda, dll)', '7199', 'Upah Borongan'],
        'asset_gain' => ['Laba pelepasan aset tetap', '7102', 'Aset Tetap'],
        'asset_loss' => ['Rugi pelepasan aset tetap', '8104', 'Aset Tetap'],
        'retained_earnings' => ['Laba ditahan', '3201', 'Ekuitas'],
        'opening_equity' => ['Ekuitas saldo awal (stok awal)', '3901', 'Ekuitas'],
    ];

    /** @var array<string, Account> */
    private array $resolved = [];

    public function account(string $role): Account
    {
        if (isset($this->resolved[$role])) {
            return $this->resolved[$role];
        }

        $code = self::code($role);
        $account = Account::where('code', $code)->first();

        if (! $account || $account->is_header) {
            throw ValidationException::withMessages([
                'account' => 'Akun "'.self::ROLES[$role][0]."\" ({$code}) tidak ditemukan atau berupa akun induk. Periksa Akuntansi → Pemetaan Akun.",
            ]);
        }

        return $this->resolved[$role] = $account;
    }

    public function id(string $role): int
    {
        return $this->account($role)->id;
    }

    public static function code(string $role): string
    {
        return (string) Setting::get('gl_'.$role, self::ROLES[$role][1]);
    }

    public function forget(): void
    {
        $this->resolved = [];
    }
}
