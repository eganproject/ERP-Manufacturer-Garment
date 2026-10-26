<x-layouts.app :title="$material->exists ? 'Ubah Bahan Baku' : 'Tambah Bahan Baku'">
    <x-card class="max-w-3xl">
        <form method="POST" action="{{ $material->exists ? route('materials.update', $material) : route('materials.store') }}">
            @csrf
            @if ($material->exists) @method('PUT') @endif
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-form.input name="code" label="Kode" :value="$material->code" required tip="Kode unik bahan agar mudah dicari dan tidak tertukar, mis. KN-COT-30S. Tidak boleh sama dengan bahan lain." placeholder="mis. KN-COT-30S" />
                <x-form.input name="name" label="Nama Bahan" :value="$material->name" required placeholder="mis. Kain Cotton Combed 30s" />
                <x-form.select name="category_id" label="Kategori" :options="$categories->pluck('name', 'id')" :value="$material->category_id" data-select />
                <x-form.select name="unit_id" tip="Satuan saat bahan dibeli, disimpan dan dipakai di BOM (mis. meter, kg, pcs). Pastikan sama dengan satuan di nota supplier." label="Satuan" :options="$units->mapWithKeys(fn ($u) => [$u->id => $u->code.' - '.$u->name])" :value="$material->unit_id" required data-select />
                <x-form.input name="color" label="Warna" :value="$material->color" placeholder="mis. Hitam" />
                <x-form.select name="default_supplier_id" tip="Supplier langganan bahan ini. Saat MRP menemukan kekurangan bahan, PO draft otomatis dibuat ke supplier ini." label="Supplier Utama" :options="$suppliers" :value="$material->default_supplier_id" placeholder="-- Tidak ada --" data-select help="Dipakai sebagai supplier default saat MRP membuat PO draft." />
                <x-form.input name="price" label="Harga Beli Standar (Rp)" tip="Perkiraan harga beli per satuan. Dipakai sebagai harga awal saat membuat PO dan untuk estimasi biaya BOM sebelum ada pembelian.
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
</x-layouts.app>
