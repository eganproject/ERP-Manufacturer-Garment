@use('App\Enums\AssetAcquisitionType')
@use('App\Enums\DepreciationMethod')
@php
    $isOpening = $type === AssetAcquisitionType::Opening;
    $catData = $categories->mapWithKeys(fn ($c) => [$c->id => ['method' => $c->depreciation_method->value, 'life' => $c->useful_life_months, 'rate' => (float) $c->residual_rate]]);
@endphp
<x-layouts.app title="Catat Aset Tetap">
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
                    <label for="f_asset_category_id" class="form-label">Kategori <span class="text-red-500">*</span></label>
                    <select id="f_asset_category_id" name="asset_category_id" class="form-control" required x-model="category" @change="pick()">
                        <option value="">-- Pilih --</option>
                        @foreach ($categories as $c)
                            <option value="{{ $c->id }}">{{ $c->code }} – {{ $c->name }}</option>
                        @endforeach
                    </select>
                    @error('asset_category_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <x-form.input name="serial_number" label="No. Seri / Plat" />
                <x-form.input name="location" label="Lokasi / Bagian" placeholder="Mis. Lantai 2 – Jahit" />
                <x-form.input name="acquisition_date" label="Tanggal Perolehan" :value="today()" required data-date />
                <x-form.input name="cost" label="Harga Perolehan (Rp, sebelum PPN)" required data-money inputmode="numeric" help="Termasuk ongkos kirim & pemasangan sampai siap pakai." />
                @if ($type === AssetAcquisitionType::Cash)
                    <x-form.select name="cash_account_id" label="Dibayar dari Rekening" :options="\App\Models\Account::cashOptions()" required data-select />
                    <x-form.select name="supplier_id" label="Supplier (opsional)" :options="$suppliers" data-select />
                @elseif ($type === AssetAcquisitionType::Credit)
                    <x-form.select name="supplier_id" label="Supplier" :options="$suppliers" required data-select />
                    <x-form.input name="due_date" label="Jatuh Tempo" data-date help="Kosong = sesuai termin supplier." />
                @endif
                @unless ($isOpening)
                    <x-form.input name="tax_rate" type="number" step="any" min="0" label="PPN Masukan (%)" value="0" />
                    <x-form.input name="reference" label="No. Faktur / Kuitansi" />
                @endunless
            </div>
        </x-card>

        <x-card title="Penyusutan">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <div>
                    <label class="form-label">Metode</label>
                    <select name="depreciation_method" class="form-control" x-model="method">
                        @foreach (DepreciationMethod::options() as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="form-label">Masa Manfaat (bulan)</label>
                    <input type="number" min="0" name="useful_life_months" class="form-control" x-model="life">
                    <p class="mt-1 text-xs text-gray-500">0 = tidak disusutkan</p>
                    @error('useful_life_months')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <x-form.input name="residual_value" label="Nilai Sisa (Rp)" data-money inputmode="numeric" help="Kosong = % nilai sisa kategori." />
                <x-form.input name="depreciation_start" label="Mulai Disusutkan" :value="$isOpening ? today()->startOfMonth() : null" data-date
                              :help="$isOpening ? 'Bulan pertama yang disusutkan sistem (biasanya bulan mulai memakai sistem).' : 'Kosong = bulan perolehan.'" />
                @if ($isOpening)
                    <x-form.input name="opening_accumulated" label="Akumulasi Penyusutan s.d. Sebelum Mulai (Rp)" data-money inputmode="numeric" class="md:col-span-2"
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
