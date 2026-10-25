# ERP Garment

Aplikasi ERP untuk manufaktur pakaian (garment): master data, Bill of Materials, pembelian bahan, penjualan, produksi (SPK) dengan tahapan cutting → sewing → finishing → QC → packing, serta persediaan dengan kartu stok.

**Stack:** Laravel 13 · PHP 8.3+ · MySQL · Tailwind CSS 4 · Vite · Alpine.js

**Library form:** Tom Select (dropdown dengan pencarian), flatpickr (pilih tanggal, bahasa Indonesia), IMask (format rupiah/angka & nomor telepon), SweetAlert2 (konfirmasi & notifikasi).

## Instalasi

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate

# buat database MySQL "erp_garment", sesuaikan DB_* di .env, lalu:
php artisan migrate --seed
php artisan storage:link   # untuk logo perusahaan

npm run build      # atau `npm run dev` saat pengembangan
php artisan serve
```

Akun contoh (password semua **password**, segera ganti di produksi):

| Email | Peran | Akses utama |
| --- | --- | --- |
| admin@erp.test | Administrator | Semua menu, pengguna, pengaturan perusahaan, log aktivitas |
| purchasing@erp.test | Purchasing | PO, supplier, bahan baku |
| gudang@erp.test | Gudang | Terima barang, kirim barang (surat jalan), stock opname |
| produksi@erp.test | Produksi | BOM, SPK produksi |
| sales@erp.test | Sales | SO, pelanggan, produk |

Matriks hak akses lengkap ada di `App\Enums\Role` dan ditampilkan di menu **Pengguna & Hak Akses**.

Seeder mengisi data contoh: satuan, kategori, 9 bahan baku dengan saldo awal, 2 supplier, 2 pelanggan, 2 produk (kaos & kemeja, ukuran S–XXL) lengkap dengan BOM.

Seeder juga menjalankan **satu siklus transaksi lengkap** selama ± 5 minggu terakhir (`FlowDemoSeeder`): SO → SPK internal & maklon → PO dengan persetujuan → penerimaan & retur pembelian → upah borongan & penggajian → surat jalan → invoice → retur penjualan → penerimaan & pembayaran → kas & bank, dengan beberapa dokumen dibiarkan terbuka agar panel **Perlu Ditindaklanjuti** di dashboard terisi.

**Panduan pengguna langkah demi langkah per peran: [docs/PANDUAN.md](docs/PANDUAN.md).**

## Modul

| Modul | Isi |
| --- | --- |
| Master Data | Satuan, kategori, supplier, pelanggan, bahan baku, produk + varian ukuran/warna |
| Bill of Materials | Kebutuhan bahan per pcs + persentase waste, simulasi kebutuhan & biaya |
| Pembelian (PO) | Draft → Dipesan → Diterima sebagian/lengkap. Penerimaan barang menambah stok bahan |
| Penjualan (SO) | Draft → Dikonfirmasi → Terkirim sebagian/lengkap. Tiap pengiriman membuat surat jalan (bisa bertahap) dan mengurangi stok barang jadi |
| Produksi (SPK) | Jumlah per ukuran, kebutuhan bahan otomatis dari BOM, mulai produksi = bahan keluar dari gudang, 5 tahapan berurutan, selesai = barang jadi masuk stok |
| Persediaan | Stok bahan & barang jadi, kartu stok (mutasi), penyesuaian / stock opname |
| Cetak PDF | PO, bukti penerimaan barang, SO, surat jalan, SPK (dengan lembar kontrol tahapan). Kop surat dari menu Pengaturan Perusahaan |
| Administrasi | Pengguna & peran, profil/ganti password, pengaturan perusahaan + logo, log aktivitas per dokumen |

Semua perubahan stok lewat `App\Services\StockService` sehingga setiap mutasi tercatat di tabel `stock_movements` beserta saldo dan dokumen referensinya. Nomor dokumen otomatis per bulan: `PO-202610-0001`, `SO-…`, `SPK-…`, `GR-…`, `SJ-…`.

## Komponen form (untuk pengembangan)

Cukup tambahkan atribut pada elemen, plugin diinisialisasi otomatis oleh `resources/js/app.js`:

- `<select data-select>` → Tom Select
- `<input data-date>` → flatpickr (nilai terkirim `Y-m-d`)
- `<input data-money>` / `data-money="2"` → format ribuan Indonesia; nilai asli dikirim ke server tanpa titik
- `<input data-phone>` → mask nomor telepon
- `<form data-confirm="Pesan">` → konfirmasi SweetAlert2 sebelum submit
- Flash `session('success')` / `session('error')` tampil sebagai toast

Komponen Blade siap pakai: `x-form.input`, `x-form.select`, `x-form.textarea`, `x-form.checkbox`, `x-card`, `x-badge`, `x-status-badge`, `x-delete-button`, `x-action-form`.

## Tes

```bash
php artisan test
```
