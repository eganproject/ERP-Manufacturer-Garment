<x-layouts.app title="Pengaturan Perusahaan">
    <x-card class="max-w-3xl" title="Profil Perusahaan (kop dokumen PDF)">
        <form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data">
            @csrf
            @method('PUT')
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-form.input name="company_name" label="Nama Perusahaan" :value="$company['name']" required class="sm:col-span-2" />
                <x-form.textarea name="company_address" label="Alamat" :value="$company['address']" rows="2" class="sm:col-span-2" />
                <x-form.input name="company_city" label="Kota" :value="$company['city']" help="Dipakai pada tanda tangan dokumen, mis. Bandung, 25 Okt 2026." tip="Kota yang dicetak sebelum tanggal di kolom tanda tangan semua dokumen PDF." />
                <x-form.input name="company_phone" label="Telepon" :value="$company['phone']" />
                <x-form.input name="company_email" type="email" label="Email" :value="$company['email']" />
                <x-form.input name="company_npwp" label="NPWP" :value="$company['npwp']" tip="NPWP perusahaan yang dicetak di kop dokumen dan invoice ber-PPN." />
                <x-form.input name="company_website" label="Website" :value="$company['website']" placeholder="www.garmencontoh.co.id" />
                <div class="sm:col-span-2">
                    <x-form.label for="f_logo" label="Logo (PNG/JPG, maks. 1 MB)" tip="Logo tampil di kop semua dokumen PDF. Gunakan gambar berlatar putih/transparan agar rapi saat dicetak." />
                    <div class="flex items-center gap-4">
                        @if ($company['logo'])
                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($company['logo']) }}" alt="Logo" class="h-14 w-auto rounded border border-gray-200 bg-white p-1">
                        @endif
                        <input type="file" id="f_logo" name="logo" accept="image/png,image/jpeg" class="form-control">
                    </div>
                    @error('logo')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    @if ($company['logo'])
                        <x-form.checkbox name="remove_logo" label="Hapus logo" class="mt-2" />
                    @endif
                </div>
            </div>

            <h3 class="mt-8 mb-1 border-t border-gray-200 pt-5 text-sm font-semibold text-gray-800">Dokumen Cetak</h3>
            <p class="mb-3 text-xs text-gray-500">Nama penanda tangan dicetak di kolom tanda tangan PDF bila dokumen belum mencatat orangnya (mis. "Disetujui oleh" di SO, "Kepala Gudang" di stock opname). Kosongkan bila ingin diisi tangan.</p>
            <div class="overflow-hidden rounded-lg border border-gray-200">
                <table class="table-base">
                    <thead><tr><th>Peran</th><th>Nama <x-tip title="Nama penanda tangan" text="Nama yang dicetak di bawah kolom tanda tangan bila dokumen tidak mencatat siapa yang menyetujui. Kosongkan bila ingin ditulis tangan." /></th><th>Jabatan dicetak <x-tip title="Jabatan" text="Jabatan yang dicetak di bawah nama. Kosong = memakai jabatan bawaan (abu-abu)." /></th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach (\App\Models\Setting::SIGNERS as $key => [$label, $defaultTitle])
                            <tr>
                                <td class="whitespace-nowrap text-sm font-medium text-gray-700">{{ $label }}</td>
                                <td><input type="text" name="signers[{{ $key }}][name]" value="{{ old("signers.$key.name", $signers[$key]['name']) }}" class="form-control" maxlength="100" aria-label="Nama {{ $label }}"></td>
                                <td><input type="text" name="signers[{{ $key }}][title]" value="{{ old("signers.$key.title", $signers[$key]['title']) }}" placeholder="{{ $defaultTitle }}" class="form-control" maxlength="100" aria-label="Jabatan {{ $label }}"></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-form.textarea name="document_footer" label="Catatan Kaki Semua Dokumen" :value="$company['footer']" rows="2" class="sm:col-span-2"
                    placeholder="Terima kasih atas kepercayaan Anda." help="Dicetak di bagian bawah setiap halaman PDF." />
                <x-form.textarea name="purchase_terms" label="Syarat & Ketentuan Purchase Order" :value="$purchaseTerms" rows="3" class="sm:col-span-2"
                    placeholder="Barang dikirim sesuai spesifikasi. Pembayaran 30 hari setelah tagihan diterima." help="Dicetak di setiap PO." />
            </div>

            <h3 class="mt-8 mb-3 border-t border-gray-200 pt-5 text-sm font-semibold text-gray-800">Invoice & Pembayaran</h3>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-form.input name="invoice_tax_rate" type="number" step="0.01" min="0" max="100" label="PPN Default (%)" :value="$invoice['tax_rate']" help="Isi 0 jika perusahaan belum PKP. Bisa diubah per invoice." tip="Tarif PPN yang otomatis terisi saat membuat invoice dan penawaran baru." />
                <div></div>
                <x-form.textarea name="invoice_bank_info" label="Rekening Pembayaran" help="Dicetak di invoice, penawaran harga, dan sales order." :value="$invoice['bank_info']" rows="3" placeholder="BCA 123-456-7890 a.n. CV Garmen Contoh" class="sm:col-span-2" />
                <x-form.textarea name="invoice_notes" label="Catatan Kaki Invoice" tip="Teks tambahan yang hanya dicetak di invoice, mis. ketentuan retur atau denda keterlambatan." :value="$invoice['invoice_notes']" rows="2" placeholder="Barang yang sudah dibeli tidak dapat dikembalikan." class="sm:col-span-2" />
            </div>

            <h3 class="mt-8 mb-3 border-t border-gray-200 pt-5 text-sm font-semibold text-gray-800">Penawaran Harga</h3>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-form.input name="quotation_validity_days" type="number" min="1" max="365" label="Masa Berlaku Default (hari)" tip="Jumlah hari sejak tanggal penawaran sampai kedaluwarsa, dipakai bila kolom Berlaku Sampai di penawaran dikosongkan." :value="$quotation['validity_days']" />
                <div></div>
                <x-form.textarea name="quotation_terms" label="Syarat & Ketentuan Default" tip="Teks awal syarat & ketentuan untuk setiap penawaran baru. Masih bisa diubah per penawaran." :value="$quotation['terms']" rows="3" placeholder="DP 50% saat PO, pelunasan sebelum barang dikirim." class="sm:col-span-2" />
            </div>

            <h3 class="mt-8 mb-3 border-t border-gray-200 pt-5 text-sm font-semibold text-gray-800">Persetujuan Pembelian</h3>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-form.input name="po_approval_threshold" label="Batas Persetujuan PO (Rp)" tip="Kontrol pengeluaran: PO senilai ini atau lebih tidak bisa langsung dipesan, harus disetujui atasan yang bukan pembuat PO." :value="$poApprovalThreshold" data-money inputmode="numeric"
                    help="PO dengan total sebesar ini atau lebih harus disetujui pengguna berhak 'Setujui PO' (Admin/Keuangan) sebelum dipesan. Isi 0 untuk menonaktifkan." />
            </div>

            <h3 class="mt-8 mb-3 border-t border-gray-200 pt-5 text-sm font-semibold text-gray-800">Penggajian Borongan</h3>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-form.select name="payroll_cycle" label="Siklus Penggajian" tip="Seberapa sering upah borongan dibayar (mingguan, dua mingguan, bulanan). Menentukan saran periode saat menyusun penggajian." :options="\App\Models\Setting::PAYROLL_CYCLES" :value="$payroll['cycle']" :placeholder="false"
                    help="Dipakai untuk menyarankan periode penggajian berikutnya. Periode tetap bisa diubah saat menyusun penggajian." />
                <x-form.select name="payroll_week_start" label="Hari Awal Periode" tip="Hari pertama setiap periode gaji. Mis. Sabtu bila periode Sabtu–Jumat dan upah dibayar tiap Jumat." :options="[1 => 'Senin', 2 => 'Selasa', 3 => 'Rabu', 4 => 'Kamis', 5 => 'Jumat', 6 => 'Sabtu', 7 => 'Minggu']" :value="$payroll['week_start']" :placeholder="false"
                    help="Untuk siklus mingguan / dua mingguan, mis. Sabtu bila upah dibayar tiap Jumat." />
            </div>
            <x-form.actions />
        </form>
    </x-card>
</x-layouts.app>
