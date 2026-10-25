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
            'purchase.receive' => 'Terima barang dari supplier',
            'sales.view' => 'Lihat SO & surat jalan',
            'sales.manage' => 'Buat/ubah/konfirmasi SO',
            'sales.deliver' => 'Kirim barang (surat jalan)',
            'production.view' => 'Lihat SPK produksi',
            'production.manage' => 'Buat & jalankan SPK produksi, catat biaya produksi',
            'mrp.view' => 'Lihat perencanaan kebutuhan bahan (MRP)',
            'costing.view' => 'Lihat HPP produksi & margin produk',
            'receivables.view' => 'Lihat invoice & piutang pelanggan',
            'receivables.manage' => 'Buat invoice & catat penerimaan pelanggan',
            'payables.view' => 'Lihat tagihan & hutang supplier',
            'payables.manage' => 'Catat tagihan & pembayaran supplier',
            'stock.view' => 'Lihat stok & kartu stok',
            'stock.adjust' => 'Penyesuaian stok (stock opname)',
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
            self::Purchasing => 'Membuat PO ke supplier (termasuk dari MRP) dan mengelola data supplier & bahan.',
            self::Gudang => 'Menerima barang, mengirim barang (surat jalan), dan stock opname.',
            self::Produksi => 'Mengelola BOM, SPK produksi dari potong sampai packing, MRP, dan biaya produksi (HPP).',
            self::Sales => 'Mengelola pelanggan dan sales order, memantau piutang.',
            self::Keuangan => 'Membuat invoice, mencatat tagihan supplier, penerimaan & pembayaran, serta umur piutang/hutang dan laporan HPP & margin.',
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
                'purchase.view', 'purchase.manage', 'purchase.receive',
                'mrp.view',
                'payables.view',
                'stock.view',
            ],
            self::Gudang => [
                'purchase.view', 'purchase.receive',
                'sales.view', 'sales.deliver',
                'production.view',
                'stock.view', 'stock.adjust',
            ],
            self::Produksi => [
                'master.basic', 'materials.manage', 'products.manage', 'boms.manage',
                'sales.view',
                'production.view', 'production.manage',
                'mrp.view', 'costing.view',
                'stock.view',
            ],
            self::Sales => [
                'customers.manage', 'products.manage',
                'sales.view', 'sales.manage',
                'receivables.view',
                'production.view',
                'stock.view',
            ],
            self::Keuangan => [
                'customers.manage', 'suppliers.manage',
                'sales.view', 'purchase.view',
                'receivables.view', 'receivables.manage',
                'payables.view', 'payables.manage',
                'costing.view',
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
