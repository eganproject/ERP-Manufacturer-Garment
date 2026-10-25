# ERP Garment – catatan untuk agen

- Laravel 13 + Blade + Tailwind 4 + Alpine. Bahasa UI: Indonesia (`APP_LOCALE=id`, pesan validasi di `lang/id/validation.php`).
- Semua perubahan stok WAJIB lewat `App\Services\StockService::move()` di dalam `DB::transaction` (mencatat `stock_movements` dan saldo).
- Logika bisnis transaksi ada di `app/Services` (Purchase/Sales/Production); controller hanya validasi + memanggil service.
- Status dokumen memakai enum di `app/Enums` (label & warna badge ada di enum).
- Nomor dokumen: `App\Services\DocumentNumber::generate()`.
- Gunakan class `form-control` (bukan `form-input`, bentrok dengan plugin @tailwindcss/forms) dan atribut `data-select`, `data-date`, `data-money` untuk plugin form.
- Jalankan `php artisan test` dan `./vendor/bin/pint` sebelum commit.
- Keuangan: invoice (AR) dibuat dari barang terkirim (`InvoiceService`), tagihan supplier (AP) dari barang diterima (`BillService`), pembayaran lewat `PaymentService` (alokasi ke banyak dokumen). Jangan ubah `paid_amount`/status invoice langsung; panggil `refreshPaymentStatus()` (trait `Payable`).
