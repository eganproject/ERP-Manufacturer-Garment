@php
    $initialVariants = old('variants', $product->variants->map(fn ($v) => [
        'id' => $v->id, 'sku' => $v->sku, 'size' => $v->size, 'color' => $v->color, 'price' => $v->price,
    ])->all());
@endphp

<x-layouts.app :title="$product->exists ? 'Ubah Produk' : 'Tambah Produk'">
    <form method="POST" action="{{ $product->exists ? route('products.update', $product) : route('products.store') }}"
          x-data="productForm(@js(array_values($initialVariants)), @js($sizes))" class="space-y-6">
        @csrf
        @if ($product->exists) @method('PUT') @endif

        <x-card title="Informasi Produk">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-form.input name="code" label="Kode Produk" tip="Kode artikel/model. Kode ini juga dipakai sebagai awalan SKU varian yang dibuat otomatis, mis. KMJ-001-HITAM-M." :value="$product->code" required placeholder="mis. KMJ-001" x-model="code" />
                <x-form.input name="name" label="Nama Produk" :value="$product->name" required placeholder="mis. Kemeja Flanel Lengan Panjang" />
                <x-form.select name="category_id" label="Kategori" :options="$categories->pluck('name', 'id')" :value="$product->category_id" data-select />
                <x-form.select name="unit_id" label="Satuan" :options="$units->mapWithKeys(fn ($u) => [$u->id => $u->code.' - '.$u->name])" :value="$product->unit_id" required data-select />
                <x-form.input name="price" label="Harga Jual Standar (Rp)" tip="Harga jual default per pcs. Otomatis terisi saat produk dipilih di penawaran atau Sales Order dan masih bisa diubah per transaksi." :value="$product->price" data-money inputmode="numeric" />
                <x-form.checkbox name="is_active" label="Aktif" :checked="$product->is_active" tip="Produk nonaktif tidak muncul lagi di pilihan SO, SPK dan BOM baru." class="sm:pt-7" />
                <x-form.textarea name="description" label="Deskripsi" :value="$product->description" class="sm:col-span-2" />
            </div>
        </x-card>

        <x-card title="Varian (Ukuran / Warna)" :padding="false" tip="Stok, penjualan dan produksi dicatat per varian (kombinasi ukuran & warna). Setiap varian punya SKU sendiri.
Ketik warna lalu klik Buat semua ukuran untuk membuat varian S s.d. XXL sekaligus.">
            <x-slot:header>
                <div class="flex flex-wrap items-center gap-2">
                    <input type="text" x-model="bulkColor" placeholder="Warna (opsional)" class="form-control w-36 py-1.5">
                    <button type="button" class="btn-secondary py-1.5" @click="generateSizes()">Buat semua ukuran</button>
                    <button type="button" class="btn-secondary py-1.5" @click="addRow()">+ Varian</button>
                </div>
            </x-slot:header>
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th class="w-48">SKU <x-tip title="SKU" text="Kode unik per varian. Terisi otomatis dari kode produk + warna + ukuran, boleh diubah. Untuk marketplace, samakan dengan SKU di Seller Center agar pesanan terhubung otomatis." /></th><th class="w-36">Ukuran</th><th>Warna</th><th class="w-48">Harga Khusus (Rp) <x-tip title="Harga khusus" text="Isi bila varian ini harganya beda dari harga produk (mis. ukuran XXL lebih mahal). Kosongkan untuk mengikuti harga jual standar." /></th><th class="w-12"></th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-for="(row, index) in rows" :key="row.key">
                            <tr>
                                <td>
                                    <input type="hidden" :name="`variants[${index}][id]`" :value="row.id ?? ''">
                                    <input type="text" :name="`variants[${index}][sku]`" x-model="row.sku" class="form-control" required>
                                </td>
                                <td>
                                    <input type="text" :name="`variants[${index}][size]`" x-model="row.size" @input="autoSku(row)" class="form-control" list="size-options" required>
                                </td>
                                <td><input type="text" :name="`variants[${index}][color]`" x-model="row.color" @input="autoSku(row)" class="form-control"></td>
                                <td>
                                    <input type="text" :name="`variants[${index}][price]`" class="form-control" inputmode="numeric" placeholder="ikut harga produk"
                                           data-money x-init="$el.value = row.price ?? ''; $nextTick(() => ERP.initPlugins($el))">
                                </td>
                                <td class="text-right">
                                    <button type="button" class="text-red-500 hover:text-red-700" @click="rows.splice(index, 1)" title="Hapus">&times;</button>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="rows.length === 0"><td colspan="5" class="py-8 text-center text-gray-500">Belum ada varian. Klik "Buat semua ukuran" atau "+ Varian".</td></tr>
                    </tbody>
                </table>
            </div>
            <datalist id="size-options">
                @foreach ($sizes as $size)<option value="{{ $size }}">@endforeach
            </datalist>
        </x-card>

        <div class="flex justify-end gap-2">
            <a href="{{ route('products.index') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary">Simpan Produk</button>
        </div>
    </form>

    <script>
        function productForm(variants, sizes) {
            let key = 0;
            return {
                code: @js(old('code', $product->code)),
                bulkColor: '',
                rows: variants.map(v => ({ ...v, key: key++, manualSku: true })),
                makeSku(row) {
                    const parts = [this.code || 'SKU', row.color, row.size].filter(Boolean);
                    return parts.join('-').toUpperCase().replace(/\s+/g, '');
                },
                autoSku(row) {
                    if (!row.manualSku) row.sku = this.makeSku(row);
                },
                addRow(size = '', color = '') {
                    const row = { key: key++, id: null, sku: '', size, color, price: '', manualSku: false };
                    row.sku = size ? this.makeSku(row) : '';
                    this.rows.push(row);
                },
                generateSizes() {
                    sizes.filter(s => s !== 'All Size').forEach(size => {
                        const exists = this.rows.some(r => r.size === size && (r.color || '') === this.bulkColor);
                        if (!exists) this.addRow(size, this.bulkColor);
                    });
                },
            };
        }
    </script>
</x-layouts.app>
