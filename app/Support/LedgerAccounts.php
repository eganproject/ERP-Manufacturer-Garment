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
        'marketplace_sales' => ['Penjualan marketplace', '4105', 'Marketplace'],
        'marketplace_receivable' => ['Piutang marketplace (dana belum cair)', '1210', 'Marketplace'],
        'marketplace_balance' => ['Saldo penjual marketplace (default bila toko tidak memilih rekening)', '1110', 'Marketplace'],
        'marketplace_voucher' => ['Voucher & promo ditanggung penjual', '4104', 'Marketplace'],
        'marketplace_fee' => ['Biaya marketplace (komisi, admin, layanan, transaksi)', '6120', 'Marketplace'],
        'marketplace_shipping' => ['Ongkos kirim ditanggung penjual', '6121', 'Marketplace'],
        'marketplace_adjustment' => ['Selisih & penyesuaian pencairan marketplace', '6122', 'Marketplace'],
        'marketplace_loss' => ['Kerugian retur & klaim marketplace (barang tidak kembali / bukan barang kita)', '6123', 'Marketplace'],
        'asset_gain' => ['Laba pelepasan aset tetap', '7102', 'Aset Tetap'],
        'asset_loss' => ['Rugi pelepasan aset tetap', '8104', 'Aset Tetap'],
        'retained_earnings' => ['Laba ditahan', '3201', 'Ekuitas'],
        'opening_equity' => ['Ekuitas saldo awal (stok awal)', '3901', 'Ekuitas'],
    ];

    /** Penjelasan kapan akun peran dipakai (tooltip di halaman Pemetaan Akun) */
    public const HINTS = [
        'cash' => 'Dipakai saat pembayaran/penerimaan bermetode Tunai dan rekening kas tidak dipilih.',
        'bank' => 'Dipakai saat pembayaran/penerimaan bermetode transfer, giro atau cek dan rekening tidak dipilih.',
        'receivable' => 'Bertambah saat invoice dibuat, berkurang saat pelanggan membayar atau ada nota kredit.',
        'sales' => 'Pendapatan dari invoice penjualan (nilai sebelum PPN).',
        'sales_discount' => 'Diskon yang diberikan di Sales Order/invoice.',
        'sales_returns' => 'Nilai barang yang dikembalikan pelanggan setelah ditagih (nota kredit).',
        'vat_out' => 'PPN yang ditagihkan ke pelanggan di invoice. Disetor ke negara saat tutup masa PPN.',
        'customer_credit' => 'Sisa nota kredit retur yang belum terpakai untuk memotong invoice pelanggan.',
        'cogs' => 'Harga pokok barang yang dikirim ke pelanggan (dicatat saat surat jalan dibuat).',
        'payable' => 'Bertambah saat tagihan supplier dicatat, berkurang saat dibayar.',
        'unbilled_payable' => 'Barang/jasa yang sudah diterima tetapi tagihannya belum dicatat. Dinolkan saat tagihan supplier masuk.',
        'vat_in' => 'PPN dari tagihan supplier & aset. Mengurangi PPN yang harus disetor saat tutup masa.',
        'vat_payable' => 'PPN kurang bayar hasil tutup masa (keluaran > masukan) yang harus disetor.',
        'vat_overpaid' => 'PPN lebih bayar hasil tutup masa (masukan > keluaran), dikompensasikan ke masa berikutnya.',
        'supplier_debit' => 'Nota debit retur pembelian yang belum terpakai memotong tagihan supplier.',
        'purchase_variance' => 'Selisih antara harga di PO (saat barang diterima) dan harga di faktur supplier.',
        'raw_material' => 'Nilai stok bahan baku di gudang. Bertambah saat penerimaan PO, berkurang saat bahan dipakai produksi.',
        'wip' => 'Biaya produksi yang sedang berjalan (bahan, upah, overhead) per SPK, sampai SPK selesai.',
        'finished_goods' => 'Nilai stok barang jadi siap jual.',
        'material_at_vendor' => 'Bahan milik kita yang sedang dititipkan di vendor maklon.',
        'wages_payable' => 'Upah produksi yang sudah dibebankan ke SPK tetapi belum dibayar.',
        'overhead_applied' => 'Lawan pembebanan overhead standar BOM ke SPK.',
        'accrued_expense' => 'Biaya produksi lain yang dibebankan ke SPK tetapi belum dibayar.',
        'production_variance' => 'Selisih antara biaya aktual SPK dan nilai barang jadi saat SPK/maklon ditutup.',
        'stock_adjustment' => 'Selisih nilai hasil stock opname atau penyesuaian stok manual.',
        'defect_inventory' => 'Nilai barang cacat di karantina (dari retur atau temuan QC).',
        'defect_writeoff' => 'Nilai barang cacat yang dimusnahkan.',
        'employee_advance' => 'Kasbon karyawan; berkurang saat dipotong dari upah.',
        'wage_allowance' => 'Bonus atau tunjangan yang ditambahkan di slip penggajian borongan.',
        'payroll_other_income' => 'Potongan lain di slip upah (mis. denda) yang menjadi pendapatan perusahaan.',
        'marketplace_sales' => 'Penjualan dari pesanan TikTok Shop/Shopee yang sudah dikirim.',
        'marketplace_receivable' => 'Dana pesanan marketplace yang belum dicairkan platform.',
        'marketplace_balance' => 'Saldo penjual di aplikasi marketplace setelah pencairan, sebelum ditarik ke bank.',
        'marketplace_voucher' => 'Voucher/promo yang ditanggung penjual (mengurangi penjualan).',
        'marketplace_fee' => 'Komisi, biaya admin, layanan dan transaksi yang dipotong platform.',
        'marketplace_shipping' => 'Ongkos kirim yang ditanggung penjual.',
        'marketplace_adjustment' => 'Selisih antara dana cair dan piutang pesanan yang tidak terjelaskan.',
        'marketplace_loss' => 'Kerugian dari retur yang barangnya tidak kembali, tertukar, atau klaim ditolak.',
        'asset_gain' => 'Keuntungan saat aset dijual di atas nilai bukunya.',
        'asset_loss' => 'Kerugian saat aset dijual di bawah nilai buku atau dihapus.',
        'retained_earnings' => 'Akumulasi laba rugi tahun-tahun sebelumnya (otomatis, tanpa jurnal penutup).',
        'opening_equity' => 'Lawan saldo awal: stok awal, aset lama dan saldo pembukaan lainnya.',
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
