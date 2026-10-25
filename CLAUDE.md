# ERP Garment – catatan untuk agen

- Laravel 13 + Blade + Tailwind 4 + Alpine. Bahasa UI: Indonesia (`APP_LOCALE=id`, pesan validasi di `lang/id/validation.php`).
- Semua perubahan stok WAJIB lewat `App\Services\StockService::move()` di dalam `DB::transaction` (mencatat `stock_movements` dan saldo).
- Barang masuk yang punya nilai (penerimaan PO, hasil produksi) kirim `unitCost:` ke `StockService::move()` agar harga pokok rata-rata (`average_cost`) ikut diperbarui; HPP SPK = bahan dikeluarkan + `production_costs`.
- Logika bisnis transaksi ada di `app/Services` (Purchase/Sales/Production); controller hanya validasi + memanggil service.
- Status dokumen memakai enum di `app/Enums` (label & warna badge ada di enum).
- Nomor dokumen: `App\Services\DocumentNumber::generate()`.
- Gunakan class `form-control` (bukan `form-input`, bentrok dengan plugin @tailwindcss/forms) dan atribut `data-select`, `data-date`, `data-money` untuk plugin form.
- Jalankan `php artisan test` dan `./vendor/bin/pint` sebelum commit.
- Keuangan: invoice (AR) dibuat dari barang terkirim (`InvoiceService`), tagihan supplier (AP) dari barang diterima (`BillService`), pembayaran lewat `PaymentService` (alokasi ke banyak dokumen). Jangan ubah `paid_amount`/status invoice langsung; panggil `refreshPaymentStatus()` (trait `Payable`).
- HPP penjualan: surat jalan menyimpan `delivery_items.unit_cost/cost` (harga pokok rata-rata saat kirim), invoice menyalin HPP rata-rata baris SO ke `invoice_items.cost`. Laba kotor = DPP invoice − HPP; tampil hanya untuk hak `costing.view`.
- Persetujuan PO: `PurchaseService::submit/approve/reject`. PO ≥ `Setting::poApprovalThreshold()` (0 = nonaktif) berstatus `PendingApproval` sampai disetujui pengguna ber-hak `purchase.approve` yang bukan pembuatnya (kecuali admin).
- Retur: `ReturnService` (retur penjualan RJ- dari SO, retur pembelian RB- dari PO). Retur mengurangi qty belum ditagih lebih dulu (`returned_quantity` vs `returned_invoiced_quantity`/`returned_billed_quantity` di baris SO/PO); kelebihannya jadi nota kredit/debit yang dipakai lewat `credit_applications` → `credited_amount` invoice/tagihan (sisa = total − paid − credited). Laporan penjualan/pembelian & dashboard sudah bersih retur.
