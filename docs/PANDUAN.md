# Panduan Alur ERP Garment

Panduan singkat menjalankan satu siklus penuh, dari pesanan pelanggan sampai laporan keuangan.
Setiap langkah menyebut **siapa** yang mengerjakan (peran) dan **menu** yang dipakai.
Panel **Perlu Ditindaklanjuti** di Dashboard menampilkan pekerjaan berikutnya untuk peran Anda, jadi mulailah hari dari sana.

Data demo (`php artisan migrate --seed`) sudah berisi satu siklus lengkap ± 5 minggu terakhir.
Masuk dengan `<peran>@erp.test` / `password` untuk melihat sudut pandang tiap peran.

## Gambaran alur

```
Penawaran ──► Sales Order ──► SPK Produksi ──► (Order Maklon) ──► Hasil kerja borongan ──► SPK selesai ──► Surat Jalan ──► Invoice ──► Penerimaan
                   │               │                                      │
                   └──► MRP ──► Purchase Order ──► Persetujuan ──► Penerimaan Barang ──► Tagihan Supplier ──► Pembayaran
                                                                          └──► Penggajian borongan ──► Bayar upah
Retur penjualan cacat ──► Stok Cacat ──► Perbaiki / Musnahkan / Kembalikan ke stok jual
Aset tetap (tunai / kredit / saldo awal) ──► Penyusutan bulanan ──► Pelepasan (jual / hapus)
Semua transaksi bernilai otomatis membuat jurnal → Buku Besar, Laba Rugi, Neraca, Arus Kas.
```

## 1. Pesanan pelanggan — Sales

0. (Opsional) **Penjualan → Penawaran Harga** → *+ Buat Penawaran*: pelanggan, perihal, produk + keterangan (sablon/bordir), harga, diskon, PPN, estimasi selesai, syarat & ketentuan; masa berlaku default dari Pengaturan (14 hari).
   **Cetak Penawaran** (PDF) lalu **Tandai Terkirim**. Bila pelanggan nego: **Buat Revisi** (nomor `-R1`, `-R2`, penawaran lama jadi *Direvisi*). Bila setuju: **Jadikan Sales Order** (SO draft dengan item, harga & diskon yang sama, target kirim = tanggal order + estimasi). Bila tidak: **Ditolak Pelanggan** (alasan wajib).
   Penawaran yang lewat masa berlaku tampil *Kedaluwarsa* dan harus direvisi dulu. Daftar penawaran menampilkan nilai ditawarkan, diterima, dan tingkat keberhasilan.
1. **Penjualan → Sales Order (SO)** → *+ Buat SO*: pilih pelanggan, produk per ukuran, qty & harga (harga terisi dari master produk).
2. Periksa lalu klik **Konfirmasi**. SO terkunci dan siap diproduksi / dikirim.
3. Bila pelanggan membatalkan sebelum ada pengiriman: **Batalkan**. Bila sebagian sudah terkirim dan sisanya tidak jadi: **Tutup Sisa SO** (wajib isi alasan). SO yang ditutup keluar dari daftar pesanan terbuka dan MRP.

## 2. Perencanaan & pembelian — Produksi / Purchasing / Keuangan

1. **Pembelian → MRP / Kebutuhan Bahan**: menghitung kebutuhan bahan dari SO terbuka + SPK draft dibanding stok, PO berjalan, dan stok minimum. Centang bahan → **Buat PO Draft** (otomatis per supplier).
2. PO manual: **Pembelian → Purchase Order (PO)** → *+ Buat PO*.
3. Di halaman PO: **Tandai Dipesan**. Jika nilai PO ≥ batas persetujuan (Pengaturan Perusahaan), tombolnya menjadi **Ajukan Persetujuan**.
4. **Keuangan** (atau Admin) membuka PO berstatus *Menunggu Persetujuan* → **Setujui** atau **Tolak** (alasan wajib). Pembuat PO tidak bisa menyetujui PO-nya sendiri.
5. **Gudang**: saat barang datang, buka PO → **Terima Barang** (bisa bertahap). Stok bahan & harga pokok rata-rata ikut diperbarui.
6. Bila supplier tidak akan mengirim sisa: **Tutup Sisa PO** (alasan wajib). Barang cacat: **Retur ke Supplier** (nota debit memotong tagihan).

## 3. Produksi — Produksi (PPIC)

1. Dari halaman SO klik **Buat SPK**: produk & qty per ukuran terisi otomatis dari sisa SO yang belum dibuatkan SPK. Bisa juga SPK untuk stok tanpa SO.
2. **Tahap yang dikerjakan vendor luar (maklon)**: sebelum mulai produksi, klik **+ Order Maklon** di SPK, pilih vendor, tahap (mis. Sewing + Finishing), qty, tarif per pcs, dan bahan yang akan dikirim.
3. Klik **Mulai Produksi**: bahan untuk pekerjaan internal keluar dari gudang; bahan untuk vendor tetap di gudang sampai dikirim lewat order maklon.
4. Maklon:
   - **Gudang** → order maklon → **Kirim Bahan** (surat jalan bahan KBM-).
   - Tahap internal sebelum tahap maklon (mis. Cutting) harus ditandai **Selesai** dulu di SPK.
   - **Gudang** → **Terima Hasil** (bagus/reject). Ongkos jasa = bagus × tarif, otomatis masuk HPP SPK.
   - **Retur Sisa Bahan** bila ada, lalu **Produksi** → **Tutup Order** (rekonsiliasi pemakaian bahan).
5. Upah borongan: **Upah Borongan → Hasil Kerja Harian** (atau *+ Input Hasil Kerja* dari SPK). Isi karyawan, operasi (atau *pcs jadi komplit*), qty bagus & reject. Upah = bagus × tarif dan langsung dibebankan ke HPP SPK.
6. Perbarui **Tahapan Produksi** di SPK (Cutting → Sewing → Finishing → QC → Packing) dengan output & reject.
7. Setelah semua tahap selesai dan order maklon ditutup: isi jumlah jadi per ukuran → **Selesaikan Produksi**. Total jadi tidak boleh melebihi output tahap Packing (formulir sudah menyarankan angkanya). Barang jadi masuk stok dengan HPP aktual.

## 4. Penggajian borongan — Produksi & Keuangan

1. **Keuangan**: kasbon karyawan dicatat di **Karyawan Produksi → (karyawan) → + Catat Kasbon**.
2. **Produksi**: **Penggajian Borongan → + Susun Penggajian** (periode default mingguan). Semua hasil kerja yang belum dibayar sampai akhir periode ikut terambil. Isi bonus, potongan kasbon, potongan lain → **Simpan**.
3. **Keuangan**: **Setujui** → **Tandai Dibayar** (pilih kas/bank). Cetak slip per karyawan.

## 5. Pengiriman & penagihan — Gudang / Keuangan

1. **Gudang**: SO → **Kirim Barang** → tombol *Isi sesuai sisa & stok* → simpan & cetak **surat jalan**. Bisa bertahap.
2. **Keuangan**: SO → **Buat Invoice** (dari barang yang sudah dikirim, PPN opsional) → cetak invoice.
3. Pembayaran pelanggan: invoice → **Catat Penerimaan** (satu penerimaan bisa melunasi beberapa invoice). Cetak kwitansi.
4. Retur dari pelanggan (**Sales/Gudang**): SO → **Retur Barang**. Retur mengurangi qty yang belum ditagih lebih dulu; kelebihannya jadi nota kredit yang otomatis memotong invoice terbuka, atau bisa dikembalikan tunai.
   Isi kolom **Di antaranya Cacat** untuk barang rusak: barang itu masuk ke **stok cacat** (karantina), bukan stok jual, sehingga tidak ikut terkirim.

## 5a. Barang cacat — Gudang / Produksi

Menu **Persediaan → Barang Cacat** menampilkan stok cacat per produk (qty & nilai HPP) dan riwayat penanganan (dokumen BC-):

- **Perbaiki (Rework)**: barang dijahit ulang lalu masuk stok jual. Biaya perbaikan (opsional, dibayar dari kas/bank) menambah HPP barang.
- **Kembalikan ke Stok Jual**: setelah dicek ternyata layak jual, pindah ke stok jual tanpa biaya.
- **Musnahkan (Scrap)**: barang tidak bisa diperbaiki; nilainya dibebankan ke *Beban Barang Cacat Dimusnahkan*.
- **Pindah ke Stok Cacat**: barang di gudang yang ternyata cacat dipisahkan dari stok jual.

Kartu stok produk menandai mutasi stok cacat dengan label *Stok Cacat*. Nilai persediaan & neraca (akun *Persediaan Barang Cacat*) sudah termasuk stok cacat. Salah input bisa **Dibatalkan** (stok kembali, jurnal dibalik).

## 6. Hutang supplier — Keuangan

1. **Keuangan → Tagihan Supplier → + Catat Tagihan**: pilih PO (dari barang yang sudah diterima) atau order maklon (dari hasil yang diterima).
2. **Bayar Tagihan** / **Pembayaran Supplier**: alokasikan ke satu atau beberapa tagihan, pilih rekening kas/bank.

## 7. Aset tetap — Keuangan

1. **Aset Tetap → Kategori Aset**: metode (garis lurus / saldo menurun), masa manfaat, nilai sisa, dan akun aset, akumulasi & beban penyusutan. Kategori standar sudah tersedia (tanah, bangunan, mesin, kendaraan, inventaris kantor).
2. **Aset Tetap → Daftar Aset Tetap → + Catat Aset**, pilih cara perolehan:
   - **Tunai / Transfer**: dibayar dari rekening kas/bank (PPN masukan opsional).
   - **Kredit**: otomatis dibuatkan **tagihan supplier**, dibayar lewat Pembayaran Supplier seperti biasa.
   - **Saldo Awal**: aset lama sebelum memakai sistem, isi tanggal perolehan asli dan akumulasi penyusutan s.d. bulan mulai disusutkan.
3. **Aset Tetap → Penyusutan Bulanan**: pratinjau lalu **Posting Penyusutan** (jurnal akhir bulan). Bulan yang terlewat ikut disusulkan. Sistem juga memposting otomatis tiap tanggal 1 untuk bulan lalu (perintah `assets:depreciate`, perlu cron `php artisan schedule:run`). Periode yang sudah ditutup tidak bisa diposting; hanya posting terakhir yang bisa dibatalkan.
4. Halaman aset menampilkan **jadwal penyusutan** (sudah diposting & proyeksi), nilai buku, dan jurnalnya.
5. **Lepas Aset**: dijual (isi harga jual & rekening penerimaan) atau dihapus/rusak. Penyusutan harus sudah diposting s.d. bulan sebelum tanggal pelepasan; selisih dengan nilai buku dijurnal sebagai laba/rugi pelepasan aset.
6. Daftar aset bisa difilter per tanggal & kategori dan diekspor ke Excel / PDF (harga perolehan, penyusutan periode, akumulasi, nilai buku).

## 7a. PPN & faktur pajak — Keuangan

1. Isi **No. Faktur Pajak** setiap transaksi ber-PPN:
   - Keluaran: buka invoice → *No. Faktur Pajak Keluaran* → **Isi** (nomor & tanggal faktur dari e-Faktur/Coretax). Nomor tercetak di PDF invoice.
   - Masukan: isi saat mencatat tagihan supplier / maklon (bisa diubah dari halaman tagihan), atau saat mencatat aset tetap tunai.
   Format 13–20 digit (mis. `010.000-26.00000001` atau 17 digit Coretax); nomor keluaran tidak boleh dipakai dua kali.
2. **Laporan → Laporan PPN**: pilih masa (bulan). Tampil daftar PPN keluaran (invoice − nota kredit retur penjualan) dan PPN masukan (tagihan supplier + aset tunai − nota debit retur pembelian), faktur yang belum diisi, serta **kurang/lebih bayar**. Ekspor Excel/PDF untuk lampiran SPT.
3. Setelah bulan berakhir: **Tutup Masa** (PPN-, berurutan per bulan). Jurnal akhir bulan memindahkan saldo PPN keluaran & masukan ke **Hutang PPN** (kurang bayar) atau **PPN Lebih Bayar** yang otomatis dikompensasikan ke masa berikutnya.
4. Setor kurang bayar lewat **Kas & Bank → Kas Keluar** dengan akun lawan *Hutang PPN*. Bila ada dokumen berubah setelah masa ditutup, laporan memberi peringatan selisih; batalkan penutupan masa terakhir lalu tutup ulang (pembetulan).

## 8. Kas, akuntansi & laporan — Keuangan

- **Keuangan → Kas & Bank**: saldo tiap rekening, mutasi, bukti kas masuk/keluar (mis. listrik, sewa) & transfer antar rekening.
- **Akuntansi**: Jurnal Umum (otomatis + manual), Buku Besar, Neraca Saldo, Laba Rugi, Neraca, Arus Kas, Anggaran vs realisasi, **Tutup Buku** per bulan (periode tertutup menolak transaksi bertanggal di dalamnya).
- **Laporan**: penjualan & pembelian (bersih retur), PPN per masa, produksi, maklon, upah borongan, nilai persediaan, HPP & margin produk, umur piutang & hutang. Semua bisa diekspor ke Excel / PDF.

## Tips

- Setiap dokumen punya **Riwayat Aktivitas** dan tautan ke dokumen asal/turunannya (SO ↔ SPK ↔ surat jalan ↔ invoice ↔ retur ↔ jurnal).
- Pembatalan dokumen bernilai tidak menghapus jurnal, tetapi membuat jurnal pembalik.
- Kelompok menu di sidebar bisa diciutkan dengan mengklik judulnya; pilihan disimpan di browser.
