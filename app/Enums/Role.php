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
            'production.manage' => 'Buat & jalankan SPK produksi',
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
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Admin => 'Akses penuh, termasuk pengguna dan pengaturan.',
            self::Purchasing => 'Membuat PO ke supplier dan mengelola data supplier & bahan.',
            self::Gudang => 'Menerima barang, mengirim barang (surat jalan), dan stock opname.',
            self::Produksi => 'Mengelola BOM dan SPK produksi dari potong sampai packing.',
            self::Sales => 'Mengelola pelanggan dan sales order.',
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
                'stock.view',
            ],
            self::Sales => [
                'customers.manage', 'products.manage',
                'sales.view', 'sales.manage',
                'production.view',
                'stock.view',
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
