<x-layouts.app :title="$material->exists ? 'Ubah Bahan Baku' : 'Tambah Bahan Baku'">
    <x-card class="max-w-3xl">
        <form method="POST" action="{{ $material->exists ? route('materials.update', $material) : route('materials.store') }}">
            @csrf
            @if ($material->exists) @method('PUT') @endif
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-form.input name="code" label="Kode" :value="$material->code" required placeholder="mis. KN-COT-30S" />
                <x-form.input name="name" label="Nama Bahan" :value="$material->name" required placeholder="mis. Kain Cotton Combed 30s" />
                <x-form.select name="category_id" label="Kategori" :options="$categories->pluck('name', 'id')" :value="$material->category_id" data-select />
                <x-form.select name="unit_id" label="Satuan" :options="$units->mapWithKeys(fn ($u) => [$u->id => $u->code.' - '.$u->name])" :value="$material->unit_id" required data-select />
                <x-form.input name="color" label="Warna" :value="$material->color" placeholder="mis. Hitam" />
                <x-form.input name="price" label="Harga Beli Standar (Rp)" :value="$material->price" data-money inputmode="numeric" />
                <x-form.input name="min_stock" label="Stok Minimum" :value="$material->min_stock" data-money="2" inputmode="decimal" help="Peringatan muncul saat stok di bawah angka ini." />
                @if ($material->exists)
                    <div>
                        <span class="form-label">Stok Saat Ini</span>
                        <p class="py-2 text-sm font-semibold">{{ qty($material->stock) }} {{ $material->unit?->code }} <span class="font-normal text-gray-500">(ubah lewat Penyesuaian Stok)</span></p>
                    </div>
                @endif
                <x-form.textarea name="notes" label="Catatan" :value="$material->notes" class="sm:col-span-2" />
                <x-form.checkbox name="is_active" label="Aktif" :checked="$material->is_active" />
            </div>
            <x-form.actions :cancel="route('materials.index')" />
        </form>
    </x-card>
</x-layouts.app>
