<x-layouts.app :title="$material->exists ? 'Ubah Bahan Baku' : 'Tambah Bahan Baku'">
    <x-card class="max-w-3xl">
        <form method="POST" action="{{ $material->exists ? route('materials.update', $material) : route('materials.store') }}">
            @csrf
            @if ($material->exists) @method('PUT') @endif
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-form.input name="code" label="Kode" :value="$material->code" required tip="Kode unik bahan agar mudah dicari dan tidak tertukar, mis. KN-COT-30S. Tidak boleh sama dengan bahan lain." placeholder="mis. KN-COT-30S" />
                <x-form.input name="name" label="Nama Bahan" :value="$material->name" required placeholder="mis. Kain Cotton Combed 30s" />
                <x-form.select name="category_id" label="Kategori" :options="$categories->pluck('name', 'id')" :value="$material->category_id" data-select />
                <x-form.select name="unit_id" tip="Satuan stok: satuan saat bahan disimpan di gudang, dipakai di BOM, SPK dan harga pokok (mis. meter, kg, pcs).
Bila supplier menjual dalam satuan lain (mis. yard), isi Satuan Beli di bawah." label="Satuan Stok" :options="$units->mapWithKeys(fn ($u) => [$u->id => $u->code.' - '.$u->name])" :value="$material->unit_id" required data-select />
                <div class="grid grid-cols-1 gap-4 rounded-lg bg-gray-50 p-4 sm:col-span-2 sm:grid-cols-2"
                     x-data="purchaseUnitForm(@js($standardFactors), @js(old('purchase_unit_id', $material->purchase_unit_id)), @js(old('purchase_conversion', $material->purchase_unit_id ? (float) $material->purchase_conversion : '')))">
                    <x-form.select name="purchase_unit_id" label="Satuan Beli (opsional)" tip="Isi bila bahan dibeli dalam satuan berbeda dari satuan stok, mis. kain dibeli per YD (yard) tetapi disimpan per M (meter).
PO & penerimaan memakai satuan ini; stok, BOM dan HPP otomatis dikonversi ke satuan stok. Kosongkan bila sama." :options="$units->mapWithKeys(fn ($u) => [$u->id => $u->code.' - '.$u->name])" :value="$material->purchase_unit_id" placeholder="-- Sama dengan satuan stok --" :native="true" x-model="unit" x-on:change="suggest()" />
                    <div x-show="unit">
                        <x-form.label for="purchase_conversion" label="Isi 1 Satuan Beli" tip="Berapa satuan stok dalam 1 satuan beli. Contoh: 1 YD = 0,9144 M; 1 ROLL = 50 M; 1 LUSIN = 12 PCS.
Terisi otomatis untuk satuan baku (yard, meter, kg, gram, lusin, kodi); untuk ROLL/BAL isi sesuai isi sebenarnya." />
                        <div class="flex items-center gap-2">
                            <span class="whitespace-nowrap text-sm text-gray-600">1 <span x-text="code(unit)"></span> =</span>
                            <input type="number" step="any" min="0" id="purchase_conversion" name="purchase_conversion" x-model="factor" class="form-control" :required="!! unit">
                            <span class="whitespace-nowrap text-sm text-gray-600" x-text="code(stock)"></span>
                        </div>
                        @error('purchase_conversion')<p class="form-error">{{ $message }}</p>@enderror
                    </div>
                </div>
                <x-form.input name="color" label="Warna" :value="$material->color" placeholder="mis. Hitam" />
                <x-form.select name="default_supplier_id" tip="Supplier langganan bahan ini. Saat MRP menemukan kekurangan bahan, PO draft otomatis dibuat ke supplier ini." label="Supplier Utama" :options="$suppliers" :value="$material->default_supplier_id" placeholder="-- Tidak ada --" data-select help="Dipakai sebagai supplier default saat MRP membuat PO draft." />
                <x-form.input name="price" label="Harga Beli Standar (Rp)" tip="Perkiraan harga beli per satuan STOK (mis. per meter, bukan per yard). Dipakai sebagai harga awal saat membuat PO dan untuk estimasi biaya BOM sebelum ada pembelian.
Harga pokok sebenarnya dihitung otomatis dari penerimaan barang." :value="$material->price" data-money inputmode="numeric" />
                <x-form.input name="min_stock" label="Stok Minimum" tip="Batas aman stok. Bila stok turun di bawah angka ini, bahan ditandai menipis di dashboard dan laporan stok supaya segera dibeli." :value="$material->min_stock" data-money="2" inputmode="decimal" help="Peringatan muncul saat stok di bawah angka ini." />
                @if ($material->exists)
                    <div>
                        <span class="form-label">Stok Saat Ini</span>
                        <p class="py-2 text-sm font-semibold">{{ qty($material->stock) }} {{ $material->unit?->code }} <span class="font-normal text-gray-500">(ubah lewat Penyesuaian Stok)</span></p>
                    </div>
                    <div>
                        <span class="form-label">Harga Pokok Rata-rata</span>
                        <p class="py-2 text-sm font-semibold">{{ rupiah($material->costPrice()) }} <span class="font-normal text-gray-500">/ {{ $material->unit?->code }} (otomatis dari penerimaan PO)</span></p>
                    </div>
                @endif
                <x-form.textarea name="notes" label="Catatan" :value="$material->notes" class="sm:col-span-2" />
                <x-form.checkbox name="is_active" label="Aktif" :checked="$material->is_active" tip="Bahan nonaktif tidak bisa dipilih di PO, BOM atau penyesuaian stok baru. Riwayat lamanya tetap tersimpan." />
            </div>
            <x-form.actions :cancel="route('materials.index')" />
        </form>
    </x-card>
    <script>
        /** Satuan beli bahan: isi otomatis faktor konversi baku (mis. YD → M = 0,9144). */
        function purchaseUnitForm(standard, unit, factor) {
            return {
                standard, unit: unit ? String(unit) : '', factor, stock: '',
                codes: @js($units->pluck('code', 'id')),
                init() {
                    const select = document.querySelector('[name=unit_id]');
                    this.stock = select?.value ?? '';
                    select?.addEventListener('change', () => { this.stock = select.value; this.suggest(); });
                },
                code(id) {
                    return this.codes[id] ?? '';
                },
                suggest() {
                    const f = this.standard[`${this.unit}:${this.stock}`];
                    if (f !== undefined) this.factor = f;
                },
            };
        }
    </script>
</x-layouts.app>
