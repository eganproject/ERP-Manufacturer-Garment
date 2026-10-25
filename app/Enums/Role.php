<?php

namespace App\Enums;

/**
 * Peran pengguna beserta hak aksesnya. Admin otomatis memiliki semua hak akses.
 */
enum Role: string
{
    case Admin = 'admin';
    case Purchasing = 'purchasing';
    case Gudang = 'gudang';
    case Produksi = 'produksi';
    case Sales = 'sales';
    case Keuangan = 'keuangan';

    /**
     * Daftar semua hak akses beserta keterangannya.
     *
     * @return array<string, string>
     */
    public static function abilities(): array
    {
        return [
            'master.basic' => 'Kelola satuan & kategori',
            'suppliers.manage' => 'Kelola supplier',
            'customers.manage' => 'Kelola pelanggan',
            'materials.manage' => 'Kelola bahan baku',
            'products.manage' => 'Kelola produk & varian',
            'boms.manage' => 'Kelola Bill of Materials',
            'purchase.view' => 'Lihat PO & penerimaan barang',
            'purchase.manage' => 'Buat/ubah/pesan PO',
            'purchase.approve' => 'Setujui / tolak PO di atas batas nilai',
            'purchase.receive' => 'Terima barang dari supplier',
            'purchase-returns.manage' => 'Catat & batalkan retur pembelian',
            'sales.view' => 'Lihat SO & surat jalan',
            'sales.manage' => 'Buat/ubah/konfirmasi SO',
            'sales.deliver' => 'Kirim barang (surat jalan)',
            'sales-returns.manage' => 'Catat & batalkan retur penjualan',
            'marketplace.view' => 'Lihat toko, pesanan & stok marketplace (TikTok Shop / Shopee)',
            'marketplace.manage' => 'Kelola toko & mapping SKU, impor pesanan, kirim & terima retur marketplace',
            'marketplace.settle' => 'Impor & posting pencairan dana marketplace',
            'production.view' => 'Lihat SPK produksi',
            'production.manage' => 'Buat & jalankan SPK produksi, catat biaya produksi',
            'subcontract.manage' => 'Buat order maklon, terima hasil & tutup order maklon',
            'subcontract.transfer' => 'Kirim bahan ke vendor maklon & terima retur sisa bahan',
            'employees.manage' => 'Kelola karyawan produksi & tarif borongan per artikel',
            'piecework.record' => 'Catat hasil kerja borongan harian',
            'payroll.view' => 'Lihat hasil kerja, penggajian borongan & slip upah',
            'payroll.manage' => 'Susun penggajian borongan per periode (draft, bonus & potongan)',
            'payroll.pay' => 'Setujui & bayar upah borongan, catat kasbon karyawan',
            'mrp.view' => 'Lihat perencanaan kebutuhan bahan (MRP)',
            'costing.view' => 'Lihat HPP produksi & margin produk',
            'receivables.view' => 'Lihat invoice & piutang pelanggan',
            'receivables.manage' => 'Buat invoice & catat penerimaan pelanggan',
            'payables.view' => 'Lihat tagihan & hutang supplier',
            'payables.manage' => 'Catat tagihan & pembayaran supplier',
            'tax.view' => 'Lihat laporan PPN (keluaran, masukan, kurang/lebih bayar per masa)',
            'tax.manage' => 'Isi nomor faktur pajak & tutup masa PPN',
            'cashbank.view' => 'Lihat rekening kas & bank, mutasi rekening, bukti kas',
            'cashbank.manage' => 'Catat kas masuk/keluar & transfer antar rekening',
            'accounting.view' => 'Lihat jurnal, buku besar & laporan keuangan (termasuk arus kas)',
            'accounting.manage' => 'Kelola bagan akun, pemetaan akun & jurnal manual',
            'accounting.close' => 'Tutup / buka periode akuntansi',
            'budget.view' => 'Lihat anggaran & laporan anggaran vs realisasi',
            'budget.manage' => 'Susun & ubah anggaran',
            'stock.view' => 'Lihat stok & kartu stok',
            'stock.adjust' => 'Penyesuaian stok & stock opname per gudang',
            'stock.transfer' => 'Transfer stok antar gudang',
            'warehouses.manage' => 'Kelola data gudang / lokasi',
            'defects.manage' => 'Proses barang cacat (pindah ke stok cacat, perbaiki, musnahkan, kembalikan ke stok jual)',
            'assets.view' => 'Lihat daftar aset tetap, jadwal & laporan penyusutan',
            'assets.manage' => 'Catat, ubah, lepas aset tetap, kelola kategori & posting penyusutan bulanan',
            'users.manage' => 'Kelola pengguna',
            'settings.manage' => 'Pengaturan perusahaan',
            'activity.view' => 'Lihat log aktivitas',
        ];
    }

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::Purchasing => 'Purchasing',
            self::Gudang => 'Gudang',
            self::Produksi => 'Produksi',
            self::Sales => 'Sales',
            self::Keuangan => 'Keuangan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Admin => 'red',
            self::Purchasing => 'blue',
            self::Gudang => 'amber',
            self::Produksi => 'indigo',
            self::Sales => 'green',
            self::Keuangan => 'gray',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Akses penuh, termasuk pengguna dan pengaturan.',
            self::Purchasing => 'Membuat PO ke supplier (termasuk dari MRP), retur pembelian, dan mengelola data supplier & bahan.',
            self::Gudang => 'Menerima barang, mengirim barang (surat jalan) & pesanan marketplace serta menerima retur marketplace, retur penjualan & pembelian, kirim/retur bahan maklon, gudang & transfer antar gudang, stock opname per gudang, serta memproses barang cacat.',
            self::Produksi => 'Mengelola BOM, SPK produksi dari potong sampai packing, order maklon ke vendor, karyawan & tarif borongan, input hasil kerja harian, menyusun penggajian borongan, MRP, biaya produksi (HPP), dan perbaikan barang cacat.',
            self::Sales => 'Mengelola pelanggan, sales order, retur penjualan, toko & pesanan marketplace (TikTok Shop / Shopee), memantau piutang.',
            self::Keuangan => 'Membuat invoice, memposting pencairan dana marketplace, mencatat tagihan supplier, penerimaan & pembayaran, nota kredit/debit retur, faktur pajak & laporan PPN, menyetujui PO bernilai besar, menyetujui & membayar upah borongan serta kasbon, kas & bank (kas masuk/keluar, transfer antar rekening), aset tetap & penyusutan, jurnal & tutup buku, anggaran, serta laporan keuangan (buku besar, neraca saldo, laba rugi, neraca, arus kas, anggaran vs realisasi), umur piutang/hutang dan HPP.',
        };
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => array_keys(self::abilities()),
            self::Purchasing => [
                'master.basic', 'suppliers.manage', 'materials.manage',
                'purchase.view', 'purchase.manage', 'purchase.receive', 'purchase-returns.manage',
                'mrp.view',
                'payables.view',
                'stock.view',
            ],
            self::Gudang => [
                'purchase.view', 'purchase.receive', 'purchase-returns.manage',
                'sales.view', 'sales.deliver', 'sales-returns.manage',
                'marketplace.view', 'marketplace.manage',
                'production.view', 'subcontract.transfer',
                'stock.view', 'stock.adjust', 'stock.transfer', 'warehouses.manage', 'defects.manage',
            ],
            self::Produksi => [
                'master.basic', 'materials.manage', 'products.manage', 'boms.manage',
                'sales.view',
                'production.view', 'production.manage',
                'subcontract.manage', 'subcontract.transfer',
                'employees.manage', 'piecework.record', 'payroll.view', 'payroll.manage',
                'mrp.view', 'costing.view',
                'stock.view', 'defects.manage',
            ],
            self::Sales => [
                'customers.manage', 'products.manage',
                'sales.view', 'sales.manage', 'sales-returns.manage',
                'marketplace.view', 'marketplace.manage',
                'receivables.view',
                'production.view',
                'stock.view',
            ],
            self::Keuangan => [
                'customers.manage', 'suppliers.manage',
                'sales.view', 'purchase.view', 'purchase.approve', 'sales-returns.manage', 'purchase-returns.manage',
                'receivables.view', 'receivables.manage',
                'marketplace.view', 'marketplace.settle',
                'payables.view', 'payables.manage',
                'costing.view', 'stock.view',
                'payroll.view', 'payroll.manage', 'payroll.pay',
                'cashbank.view', 'cashbank.manage',
                'tax.view', 'tax.manage',
                'accounting.view', 'accounting.manage', 'accounting.close',
                'budget.view', 'budget.manage',
                'assets.view', 'assets.manage',
            ],
        };
    }

    public function allows(string $ability): bool
    {
        return in_array($ability, $this->permissions(), true);
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $r) => [$r->value => $r->label()])->all();
    }
}
