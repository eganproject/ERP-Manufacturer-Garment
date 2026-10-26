@use('App\Enums\AssetAcquisitionType')
@use('App\Enums\DepreciationMethod')
@php
    $isOpening = $type === AssetAcquisitionType::Opening;
    $catData = $categories->mapWithKeys(fn ($c) => [$c->id => ['method' => $c->depreciation_method->value, 'life' => $c->useful_life_months, 'rate' => (float) $c->residual_rate]]);
@endphp
<x-layouts.app title="Catat Aset Tetap">
    <x-guide title="Pilih cara perolehan aset" :steps="['**Tunai / Transfer**: aset dibayar langsung dari rekening kas/bank.', '**Kredit**: sistem otomatis membuat tagihan supplier yang dibayar lewat Pembayaran Supplier.', '**Saldo Awal**: aset lama sebelum memakai sistem; isi tanggal perolehan asli dan akumulasi penyusutan sebelumnya.', 'Setelah dicatat, penyusutan diposting tiap bulan di menu **Penyusutan Bulanan** (juga otomatis tiap tanggal 1).']"></x-guide>
    <div class="mb-4 flex flex-wrap gap-2">
        @foreach (AssetAcquisitionType::cases() as $option)
            <a href="{{ route('fixed-assets.create', ['type' => $option->value]) }}"
               @class(['rounded-full border px-3 py-1 text-sm', 'border-indigo-500 bg-indigo-50 font-medium text-indigo-700' => $option === $type, 'border-gray-200 text-gray-600 hover:border-indigo-300' => $option !== $type])>{{ $option->label() }}</a>
        @endforeach
    </div>

    <form method="POST" action="{{ route('fixed-assets.store') }}" class="space-y-6"
          x-data="{ cats: @js($catData), category: @js((string) old('asset_category_id', '')), method: @js(old('depreciation_method', '')), life: @js(old('useful_life_months', '')), residual: @js(old('residual_value', '')),
                    pick() { const c = this.cats[this.category]; if (c) { this.method = c.method; this.life = c.life; } } }"
          data-confirm="Aset akan dicatat dan dijurnal{{ $type === AssetAcquisitionType::Credit ? ', tagihan supplier otomatis dibuat' : '' }}." data-confirm-icon="question" data-confirm-button="Ya, simpan">
        @csrf
        <input type="hidden" name="acquisition_type" value="{{ $type->value }}">

        <x-card :title="'Data Aset · '.$type->label()">
            <p class="mb-4 text-sm text-gray-500">{{ $type->hint() }}</p>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <x-form.input name="name" label="Nama Aset" required class="md:col-span-2" placeholder="Mis. Mesin jahit high speed Juki DDL-8100" />
                <div>
                    <x-form.label for="f_asset_category_id" label="Kategori" :required="true" tip="Kategori menentukan akun aset/penyusutan serta metode & masa manfaat awal. Bisa disesuaikan di bagian Penyusutan." />
                    <select id="f_asset_category_id" name="asset_category_id" class="form-control" required x-model="category" @change="pick()">
                        <option value="">-- Pilih --</option>
                        @foreach ($categories as $c)
                            <option value="{{ $c->id }}">{{ $c->code }} – {{ $c->name }}</option>
                        @endforeach
                    </select>
                    @error('asset_category_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <x-form.input name="serial_number" label="No. Seri / Plat" tip="Nomor seri mesin atau nomor polisi kendaraan untuk identifikasi fisik." />
                <x-form.input name="location" label="Lokasi / Bagian" tip="Tempat aset berada atau bagian pemakai. Memudahkan pengecekan fisik aset." placeholder="Mis. Lantai 2 – Jahit" />
                <x-form.input name="acquisition_date" label="Tanggal Perolehan" tip="Tanggal aset dibeli/diterima. Untuk saldo awal, isi tanggal pembelian aslinya." :value="today()" required data-date />
                <x-form.input name="cost" label="Harga Perolehan (Rp, sebelum PPN)" required data-money inputmode="numeric" help="Termasuk ongkos kirim & pemasangan sampai siap pakai." tip="Total biaya sampai aset siap dipakai, tanpa PPN. Nilai inilah yang disusutkan." />
                @if ($type === AssetAcquisitionType::Cash)
                    <x-form.select name="cash_account_id" label="Dibayar dari Rekening" tip="Rekening kas/bank yang dipakai membayar aset. Saldonya berkurang sebesar harga + PPN." :options="\App\Models\Account::cashOptions()" required data-select />
                    <x-form.select name="supplier_id" label="Supplier (opsional)" :options="$suppliers" data-select />
                @elseif ($type === AssetAcquisitionType::Credit)
                    <x-form.select name="supplier_id" label="Supplier" :options="$suppliers" required data-select />
                    <x-form.input name="due_date" label="Jatuh Tempo" data-date help="Kosong = sesuai termin supplier." tip="Batas bayar tagihan aset ke supplier. Tagihan dibuat otomatis dan dibayar lewat Pembayaran Supplier." />
                @endif
                @unless ($isOpening)
                    <x-form.input name="tax_rate" type="number" step="any" min="0" label="PPN Masukan (%)" tip="Tarif PPN di faktur pembelian aset (mis. 11). PPN tidak menambah harga aset, tetapi dicatat sebagai PPN masukan." value="0" />
                    <x-form.input name="reference" label="No. Faktur / Kuitansi" />
                    <x-form.input name="tax_invoice_number" label="No. Faktur Pajak" placeholder="Bila ada PPN masukan" help="Untuk laporan PPN masukan." tip="Nomor faktur pajak dari penjual aset agar PPN-nya bisa dikreditkan di Laporan PPN." />
                @endunless
            </div>
        </x-card>

        <x-card title="Penyusutan">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <div>
                    <x-form.label label="Metode" tip="Garis lurus: penyusutan sama setiap bulan (nilai disusutkan ÷ masa manfaat).
Saldo menurun: penyusutan besar di awal lalu mengecil (nilai buku awal tahun × 2 ÷ masa manfaat), cocok untuk mesin/kendaraan." />
                    <select name="depreciation_method" class="form-control" x-model="method">
                        @foreach (DepreciationMethod::options() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-form.label label="Masa Manfaat (bulan)" tip="Perkiraan lama aset dipakai, dalam bulan. Mis. mesin jahit 96 (8 tahun), kendaraan 96, inventaris kantor 48. Isi 0 untuk aset yang tidak disusutkan seperti tanah." />
                    <input type="number" min="0" name="useful_life_months" class="form-control" x-model="life">
                    <p class="mt-1 text-xs text-gray-500">0 = tidak disusutkan</p>
                    @error('useful_life_months')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <x-form.input name="residual_value" label="Nilai Sisa (Rp)" data-money inputmode="numeric" help="Kosong = % nilai sisa kategori." tip="Perkiraan nilai aset di akhir masa manfaat. Penyusutan berhenti saat nilai buku mencapai angka ini." />
                <x-form.input name="depreciation_start" label="Mulai Disusutkan" tip="Bulan pertama penyusutan dibukukan. Biasanya bulan aset mulai dipakai." :value="$isOpening ? today()->startOfMonth() : null" data-date
                              :help="$isOpening ? 'Bulan pertama yang disusutkan sistem (biasanya bulan mulai memakai sistem).' : 'Kosong = bulan perolehan.'" />
                @if ($isOpening)
                    <x-form.input name="opening_accumulated" label="Akumulasi Penyusutan s.d. Sebelum Mulai (Rp)" tip="Total penyusutan yang sudah dibukukan di pembukuan lama sebelum bulan mulai disusutkan. Nilai buku awal = harga perolehan − angka ini." data-money inputmode="numeric" class="md:col-span-2"
                                  help="Dari daftar aset / laporan keuangan lama. Masa manfaat dihitung dari tanggal perolehan." />
                @endif
                <x-form.textarea name="notes" label="Catatan" rows="2" class="md:col-span-4" />
            </div>
        </x-card>

        <div class="flex justify-end gap-2">
            <a href="{{ route('fixed-assets.index') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary">Simpan Aset</button>
        </div>
    </form>
</x-layouts.app>
