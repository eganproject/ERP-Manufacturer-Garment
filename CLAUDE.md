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
- Maklon (subkontrak): `SubcontractService` (order MKL-, kirim bahan KBM-, retur RBM-, terima hasil TMK-). Vendor = `Supplier` dengan `is_subcontractor`. Bahan yang dikirim ke vendor langsung dibebankan ke SPK (`production_materials.issued_quantity`), retur menguranginya; saldo titipan = `subcontract_order_materials` (dikirim − diretur − terpakai). Ongkos jasa (bagus × tarif) jadi `production_costs` tipe CMT dan ditagih lewat `BillService::createFromSubcontract` (bill tanpa PO). Tahap SPK yang dimaklonkan (`production_stages.subcontract_order_id`) hanya diperbarui lewat penerimaan hasil; SPK baru bisa selesai setelah order maklon ditutup.
