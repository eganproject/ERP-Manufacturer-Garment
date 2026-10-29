# Panduan Alur ERP Garment

Panduan singkat menjalankan satu siklus penuh, dari pesanan pelanggan sampai laporan keuangan.
Setiap langkah menyebut **siapa** yang mengerjakan (peran) dan **menu** yang dipakai.
Panel **Perlu Ditindaklanjuti** di Dashboard menampilkan pekerjaan berikutnya untuk peran Anda, jadi mulailah hari dari sana.
Di halaman setiap dokumen (SO, PO, SPK, penawaran, invoice, tagihan) ada kotak **Langkah berikutnya** yang menjelaskan tombol apa yang perlu diklik sekarang. Tombol langkah utama selalu terlihat di kanan atas; aksi lain (Cetak, Ubah, Tutup Sisa, Batalkan, Hapus) ada di menu **Lainnya**.

Data demo (`php artisan migrate --seed`) sudah berisi satu siklus lengkap ± 5 minggu terakhir.
Masuk dengan `<peran>@erp.test` / `password` untuk melihat sudut pandang tiap peran.

## Persiapan: Pengaturan Perusahaan & dokumen cetak

**Admin** → menu **Pengaturan Perusahaan**. Isi sekali, otomatis dipakai semua dokumen PDF:

- Nama, alamat, kota, telepon, email, website, NPWP & logo → kop setiap dokumen; kota dipakai di tanggal tanda tangan.
- **Penanda tangan** (pimpinan, keuangan, pembelian, kepala gudang, kepala produksi) beserta jabatannya → mengisi kolom tanda tangan yang belum tercatat orangnya (mis. *Disetujui oleh* di SO, *Kepala Gudang* di stock opname). Kosongkan bila ingin ditulis tangan.
- **Catatan kaki semua dokumen** → dicetak di bawah setiap halaman. **Syarat & ketentuan PO** → dicetak di setiap Purchase Order.
- **Rekening pembayaran** → dicetak di invoice, penawaran harga, dan sales order.

Dokumen yang bisa dicetak (tombol **Cetak** di halaman dokumen, atau **Lainnya → Cetak**): BOM (lembar kebutuhan bahan per jumlah produksi), penawaran, SO, surat jalan, invoice, kwitansi/bukti bayar, PO, penerimaan barang, tagihan supplier, SPK, order/kirim/terima maklon, retur, hasil kerja borongan, kasbon, penggajian & slip upah, bukti kas/bank, jurnal, barang cacat, transfer gudang, stock opname, pencairan marketplace, dan semua laporan.

## Persiapan: Impor data awal dari Excel

Menu **Master Data → Impor Data (Excel)** (atau tombol **Impor Excel** di daftar pelanggan, supplier, bahan, produk, karyawan, dan **Impor Stok Awal** di halaman stok). Semua impor menerima **Excel (.xlsx / .xls)** maupun CSV.

1. Pilih jenis data → **Unduh Template Excel**. Template berisi sheet **Data** (diisi), **Contoh**, dan **Petunjuk** (penjelasan tiap kolom). Kolom bertanda * wajib.
2. Urutan yang disarankan saat mulai memakai sistem: **Supplier & Vendor Maklon → Bahan Baku → Produk & Varian → Pelanggan → Karyawan Produksi → Stok Awal → Jurnal / Saldo Awal Akun**.
3. Unggah file. Semua baris diperiksa dulu: bila ada satu kesalahan, **tidak ada yang disimpan** dan sistem menampilkan nomor baris + penyebabnya. Perbaiki lalu unggah ulang.
4. Kode / SKU yang sudah ada **diperbarui** (sel kosong = nilai lama tetap). Untuk ubah massal (mis. naikkan harga): **Unduh Data Saat Ini** → ubah di Excel → unggah lagi.
5. Catatan per jenis:
   - **Produk & Varian**: satu baris = satu SKU; ulangi Kode Produk untuk setiap ukuran/warna. Satuan & kategori yang belum ada dibuat otomatis.
   - **Stok Awal**: menambah stok per gudang dengan **harga pokok per unit** (wajib untuk barang jadi yang belum punya harga pokok). Nilainya dijurnal ke Persediaan lawan Ekuitas Saldo Awal. Mengimpor file yang sama dua kali = stok dobel.
   - **Jurnal / Saldo Awal Akun**: baris dengan Tanggal + Keterangan + No. Referensi sama menjadi satu jurnal manual (JU-) dan harus seimbang. Dipakai untuk saldo awal kas, bank, modal, piutang/hutang lama.

## Gambaran alur

```
Penawaran ──► Sales Order ──► SPK Produksi ──► (Order Maklon) ──► Hasil kerja borongan ──► SPK selesai ──► Surat Jalan ──► Invoice ──► Penerimaan
                   │               │                                      │
                   └──► MRP ──► Purchase Order ──► Persetujuan ──► Penerimaan Barang ──► Tagihan Supplier ──► Pembayaran
                                                                          └──► Penggajian borongan ──► Bayar upah
Marketplace (TikTok Shop / Shopee): Impor pesanan ──► Kirim (stok gudang toko keluar) ──► Selesai / Retur ──► Impor pencairan dana ──► Posting
Pesanan PO marketplace: Impor (PO + batas kirim) ──► Menunggu Produksi ──► Rencana Produksi ──► SPK ──► MRP/PO bahan ──► SPK selesai ──► Siap Dikirim ──► Daftar Ambil Barang ──► Kirim
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

## 1a. Penjualan marketplace (TikTok Shop & Shopee) — Sales / Gudang / Keuangan

Hanya untuk dua platform: **TikTok Shop** dan **Shopee**.

1. **Marketplace → Toko Marketplace** → *+ Tambah Toko* (Sales / Gudang): platform, nama toko, **gudang sumber stok** (disarankan gudang khusus online, mis. *Gudang Online*), PPN dalam harga (isi 11 bila PKP), dan rekening **Saldo Penjual** (default akun *1110 Saldo Penjual Marketplace*, sudah tercatat sebagai rekening kas). Kredensial API bersifat opsional.
2. **Mapping SKU**: SKU penjual di Seller Center yang sama persis dengan SKU varian ERP terpetakan otomatis. Yang berbeda dipetakan di halaman ini; isi *Isi per Paket* untuk bundling (mis. 1 SKU = 3 pcs). Untuk produk **PO (pre-order)** isi **Hari PO** (mis. 30); bisa massal: centang SKU → isi hari → *Terapkan*. 0 = ready stock.
3. **Pesanan Marketplace → Impor Pesanan**: unggah file ekspor pesanan dari Seller Center (.xlsx / .xls / .csv) atau template Excel. Impor ulang file yang lebih baru aman karena pesanan tidak digandakan dan status ikut diperbarui:
   - *Belum Bayar* / *Perlu Dikirim*: belum ada efek stok. Pesanan Perlu Dikirim **mengalokasikan** stok di **Stok Marketplace** (stok tersedia = stok gudang − pesanan perlu dikirim, bisa diunduh ke Excel untuk update stok massal di Seller Center).
   - *Dikirim* / *Selesai*: stok keluar dari gudang toko, penjualan dicatat **Dr Piutang Marketplace / Cr Penjualan Marketplace (+ PPN)** dan HPP. Bisa juga dikirim manual (*Kirim Pesanan* / centang lalu *Kirim yang Dipilih*).
   - **PO & batas kirim**: batas kirim diambil dari kolom *Pesanan Harus Dikirimkan Sebelum* (Shopee) bila ada; kolom *Normal or Pre-order* (TikTok) / *Pre-order* (template) menandai PO. Bila tidak ada, batas kirim = tanggal bayar + **Hari PO** SKU (pesanan PO) atau + **Hari Proses Ready Stock** toko (default 2). Bisa diubah per pesanan (*Jenis & batas kirim → Ubah*).
   - **Alokasi stok**: stok gudang toko dibagikan ke pesanan Perlu Dikirim mulai **batas kirim terdekat**. Pesanan yang semua barangnya kebagian = **Siap Dikirim**; sisanya **Menunggu Produksi** (stok tidak ditahan untuk pesanan yang belum lengkap). Alokasi otomatis diperbarui saat hasil SPK masuk gudang.
   - **Produksi → Rencana Produksi**: kekurangan per ukuran = pesanan marketplace + sisa SO − stok jadi − SPK berjalan, dengan batas kirim terdekat. Centang artikel, sesuaikan jumlah, pilih gudang toko sebagai gudang hasil, lalu **Buat SPK Draft** (target selesai = 1 hari sebelum batas kirim). MRP juga ikut menghitung pesanan marketplace.
   - **Daftar Ambil Barang** (tombol di Pesanan Marketplace): PDF total per SKU + rincian pesanan Siap Dikirim untuk gudang/packing. Dashboard memberi peringatan pesanan PO yang batas kirimnya ≤ 3 hari tetapi barang belum siap.
   - *Batal* sebelum dikirim: tanpa efek. *Batal* setelah dikirim (gagal kirim) atau *Pengembalian*: status **Retur Diproses**, menunggu paket kembali.
   - SKU yang belum dipetakan menahan pengiriman; daftar peringatan muncul setelah impor.
4. **Terima retur** (Gudang, di halaman pesanan): pilih **kondisi paket saat dibuka**, lalu per barang isi qty direfund, qty barang **kita** yang kembali, dan berapa yang cacat. Rekam video unboxing.
   - *Sesuai* → stok kembali ke gudang toko. *Cacat* → masuk stok cacat (diproses di Barang Cacat).
   - *Bukan barang kita / tertukar*, *paket kosong / isi kurang*, *paket tidak sampai*: isi **Kembali = 0**. Barang asing **tidak** masuk stok. HPP barang yang hilang dicatat ke **Kerugian Retur & Klaim Marketplace**, lalu muncul kartu **Banding**.
   - *Refund tanpa pengembalian barang* / refund sebagian: isi **Nilai Refund** sesuai nominal dari platform.
   - Penjualan & PPN bagian yang direfund dibalik (Retur Penjualan), piutang marketplace berkurang.
5. **Banding / klaim**: ajukan banding di Seller Center dengan bukti (video unboxing, foto paket & resi retur), lalu *Catat Banding Diajukan*. Saat ada keputusan, *Simpan Hasil Banding*. Bila **diterima**, kompensasi menjadi piutang marketplace (mengurangi kerugian) dan tertutup ketika kompensasi ikut dicairkan. Bila **ditolak**, kerugian tetap.
6. **Pencairan Dana → Impor Laporan Penghasilan** (Keuangan): unggah laporan penghasilan / settlement (Shopee: *Penghasilan Saya*; TikTok: rincian settlement). Hasilnya berupa draft per pesanan: nilai pesanan, voucher penjual, komisi/biaya admin, biaya layanan, biaya transaksi, ongkir ditanggung penjual, biaya lain (afiliasi), penyesuaian, dan dana bersih. Komponen yang tidak terbaca masuk ke penyesuaian agar dana bersih selalu sesuai file.
   - Periksa peringatan: pesanan belum dikirim (impor ulang file pesanan), pesanan tidak ditemukan (*Cocokkan Ulang*), atau pesanan yang **sudah pernah cair** (cegah file ganda).
   - **Posting**: Dr Saldo Penjual + Voucher + Biaya Marketplace + Ongkir Penjual ± Selisih / Cr Piutang Marketplace. Retur yang terjadi setelah dana cair tampil sebagai *kelebihan cair* dan tertutup oleh potongan di pencairan berikutnya.
   - Penarikan saldo ke rekening bank: **Kas & Bank → Transfer** dari *Saldo Penjual Marketplace* ke bank.
7. **Laporan → Laporan Marketplace**: penjualan, retur, voucher, biaya platform, ongkir, HPP & margin per platform/toko, per produk, atau per pesanan, serta pesanan yang belum cair. PPN penjualan marketplace masuk **Laporan PPN** (digunggung per toko).

## 2. Perencanaan & pembelian — Produksi / Purchasing / Keuangan

1. **Pembelian → MRP / Kebutuhan Bahan**: menghitung kebutuhan bahan dari SO terbuka + SPK draft dibanding stok, PO berjalan, dan stok minimum. Centang bahan → **Buat PO Draft** (otomatis per supplier).
2. PO manual: **Pembelian → Purchase Order (PO)** → *+ Buat PO*.
3. Di halaman PO: **Tandai Dipesan**. Jika nilai PO ≥ batas persetujuan (Pengaturan Perusahaan), tombolnya menjadi **Ajukan Persetujuan**.
4. **Keuangan** (atau Admin) membuka PO berstatus *Menunggu Persetujuan* → **Setujui** atau **Tolak** (alasan wajib). Pembuat PO tidak bisa menyetujui PO-nya sendiri.
5. **Gudang**: saat barang datang, buka PO → **Terima Barang** (bisa bertahap). Stok bahan & harga pokok rata-rata ikut diperbarui.
6. Bila supplier tidak akan mengirim sisa: **Tutup Sisa PO** (alasan wajib). Barang cacat: **Retur ke Supplier** (nota debit memotong tagihan).

## 3. Produksi — Produksi (PPIC)

0. **BOM (resep 1 pcs)**: bahan yang beda per warna/ukuran cukup diisi kolom **Ukuran** / **Warna** di baris bahan (mis. *Kain hitam* → warna Hitam; *Kain hitam 0,25 kg* → ukuran XL, XXL dan baris lain 0,22 kg untuk S, M, L). Kosong = dipakai semua varian. SPK & MRP menghitung bahan sesuai jumlah per varian, halaman BOM menampilkan HPP standar per varian.
1. Dari halaman SO klik **Buat SPK**: produk & qty per ukuran terisi otomatis dari sisa SO yang belum dibuatkan SPK. Bisa juga SPK untuk stok tanpa SO.
2. **Tahap yang dikerjakan vendor luar (maklon)**: sebelum mulai produksi, klik **+ Order Maklon** di SPK (atau menu **Maklon → + Buat Order Maklon**, lalu pilih SPK-nya), pilih vendor, tahap (mis. Sewing + Finishing), qty, tarif per pcs, dan bahan yang akan dikirim.
3. Klik **Mulai Produksi**: bahan untuk pekerjaan internal keluar dari gudang; bahan untuk vendor tetap di gudang sampai dikirim lewat order maklon.
4. Maklon:
   - **Gudang** → order maklon → **Kirim Bahan** (surat jalan bahan KBM-).
   - Tahap internal sebelum tahap maklon (mis. Cutting) harus ditandai **Selesai** dulu di SPK.
   - **Gudang** → **Terima Hasil** (bagus/reject). Ongkos jasa = bagus × tarif, otomatis masuk HPP SPK.
   - **Retur Sisa Bahan** bila ada, lalu **Produksi** → **Tutup Order** (rekonsiliasi pemakaian bahan).
5. Upah borongan: **Upah Borongan → Hasil Kerja Harian** (atau *+ Input Hasil Kerja* dari SPK). Isi karyawan, operasi (atau *pcs jadi komplit*), qty bagus & reject. Upah = bagus × tarif dan langsung dibebankan ke HPP SPK.
6. Perbarui **Tahapan Produksi** di SPK (Cutting → Sewing → Finishing → QC → Packing) dengan output & reject.
7. **Bahan kurang / sisa bahan** (menu **Lainnya** di SPK yang berjalan):
   - **Bahan Tambahan** (BBT-): kain kurang, salah potong, atau bahan di luar BOM. Stok gudang berkurang dan nilainya menambah HPP SPK.
   - **Retur Sisa Bahan** (RSB-): sisa bahan yang masih layak kembali ke gudang dan mengurangi HPP SPK. Maksimal sebesar bahan yang dipegang produksi internal (sisa di vendor maklon diretur lewat order maklon).
8. **Setor Hasil** (HPJ-) bertahap: hasil yang sudah selesai packing bisa langsung masuk gudang tanpa menunggu SPK selesai, mis. untuk mengejar batas kirim PO marketplace. Isi pcs **bagus** (stok jual) dan **grade B** (reject yang masih bisa diperbaiki / dijual murah → stok **Barang Cacat**). Nilainya memakai **HPP sementara** = perkiraan biaya penuh SPK (bahan + tarif borongan + tarif maklon + biaya lain) ÷ rencana pcs.
9. Setelah semua tahap selesai dan order maklon ditutup: isi **sisa** jumlah jadi & grade B per ukuran → **Selesaikan Produksi**. Total jadi bagus tidak boleh melebihi output tahap Packing (formulir sudah menyarankan angkanya). Setoran penutup menyerap selisih biaya aktual, sehingga total nilai semua setoran = HPP aktual SPK; sisa pembulatan masuk Selisih Biaya Produksi.

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

## 5b. Multi-gudang, transfer & stock opname — Gudang

- **Persediaan → Gudang**: daftar gudang/lokasi (mis. *GU Gudang Utama*, *GJ Gudang Barang Jadi*) beserta stok & nilainya. Gudang default dipakai bila gudang tidak dipilih. Gudang yang sudah punya mutasi tidak bisa dihapus, cukup dinonaktifkan.
- Setelah ada lebih dari satu gudang, form **Terima Barang**, **Kirim Barang**, SPK (bahan keluar & hasil masuk), **Kirim/Retur Bahan Maklon**, retur, barang cacat dan penyesuaian stok punya pilihan **Gudang**. Stok dicek per gudang: barang tidak bisa dikirim dari gudang yang kosong walau gudang lain ada stok.
- **Stok Bahan/Barang Jadi** & **Kartu Stok** bisa difilter per gudang (saldo per gudang); tanpa filter tampil total dengan rincian per gudang. Harga pokok rata-rata tetap satu untuk semua gudang.
- **Transfer Antar Gudang** (TG-): pilih gudang asal & tujuan, isi barang & jumlah, cetak surat transfer. Tidak memengaruhi nilai/jurnal. Bisa dibatalkan selama stok di gudang tujuan masih cukup.
- **Stock Opname** (OPN-) per gudang & jenis barang (bahan / barang jadi, bisa per kategori): buat lembar hitung → **Cetak Lembar Hitung** (tanpa angka sistem) → isi jumlah fisik (boleh bertahap, *Simpan Hasil Hitung*) → **Posting Opname**. Selisih dihitung terhadap stok gudang saat posting dan dijurnal ke *Selisih Persediaan*; barang yang tidak diisi tidak disesuaikan. Setelah diposting tersedia berita acara opname.

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
- **Laporan**: penjualan & pembelian (bersih retur), PPN per masa, produksi, maklon, upah borongan, nilai persediaan, HPP & margin produk, umur piutang & hutang (rincian per invoice/tagihan). Semua bisa diekspor ke Excel / PDF.

## Tips

- Ikon **?** di samping nama kolom menjelaskan fungsi kolom itu dan pengaruhnya ke alur. Di komputer cukup arahkan kursor; di HP ketuk ikonnya (ketuk di luar untuk menutup).
- Form utama punya kotak **panduan singkat** berisi langkah-langkah alur. Klik judulnya untuk menyembunyikan/menampilkan; pilihan diingat per halaman.
- Setiap dokumen punya **Riwayat Aktivitas** dan tautan ke dokumen asal/turunannya (SO ↔ SPK ↔ surat jalan ↔ invoice ↔ retur ↔ jurnal).
- Pembatalan dokumen bernilai tidak menghapus jurnal, tetapi membuat jurnal pembalik.
- Kelompok menu di sidebar bisa diciutkan dengan mengklik judulnya; pilihan disimpan di browser.
- Tekan **Ctrl + K** (⌘ + K di Mac) atau klik **Cari menu…** di sidebar untuk melompat ke menu mana pun atau langsung membuat dokumen baru (SO, PO, SPK, pembayaran, jurnal, dll.). Gunakan panah ↑/↓ lalu Enter.
- Semua kolom pilihan bisa diketik untuk mencari; kolom tanggal & bulan membuka kalender. Angka rupiah otomatis diberi titik ribuan.
