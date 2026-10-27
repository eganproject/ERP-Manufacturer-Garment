@php
    $initialItems = old('items', $bom->items->map(fn ($i) => [
        'material_id' => $i->material_id, 'quantity' => (float) $i->quantity, 'waste_percent' => (float) $i->waste_percent, 'notes' => $i->notes,
    ])->all());
@endphp

<x-layouts.app :title="$bom->exists ? 'Ubah BOM' : 'Buat BOM'">
    <x-guide title="Apa itu BOM?" :steps="['BOM (Bill of Materials) adalah **resep produksi**: bahan apa saja dan berapa banyak untuk membuat **1 pcs** produk.', 'SPK memakai BOM aktif untuk menghitung bahan yang dikeluarkan dari gudang saat **Mulai Produksi**.', 'MRP memakai BOM untuk menghitung kekurangan bahan dari Sales Order yang masih terbuka.', 'Upah & overhead per pcs ikut dibebankan ke HPP SPK, sehingga harga pokok produk bisa dihitung.']"></x-guide>
    <form method="POST" action="{{ $bom->exists ? route('boms.update', $bom) : route('boms.store') }}"
          x-data="lineItems(@js(array_values($initialItems)), { material_id: '', quantity: '', waste_percent: 0, notes: '' })" class="space-y-6">
        @csrf
        @if ($bom->exists) @method('PUT') @endif

        <x-card title="Informasi BOM">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-form.select name="product_id" tip="Produk jadi yang dibuat dengan resep ini. Satu produk boleh punya beberapa BOM (mis. versi bahan berbeda), tapi hanya satu yang aktif." label="Produk" :options="$products->mapWithKeys(fn ($p) => [$p->id => $p->code.' - '.$p->name])" :value="$bom->product_id" required data-select />
                <x-form.input name="name" label="Nama BOM" tip="Nama versi resep agar mudah dibedakan, mis. BOM Standar v1 atau BOM Kain Rayon." :value="$bom->name" required placeholder="mis. BOM Standar v1" />
                <x-form.input name="labor_cost" label="Upah Jahit / CMT per pcs (Rp)" :value="$bom->labor_cost" data-money inputmode="numeric" help="Dibebankan otomatis ke SPK saat produksi dimulai." tip="Perkiraan upah jahit per pcs untuk produksi internal. Masuk ke HPP SPK saat produksi dimulai.
Tidak dipakai bila tahap jahit dimaklonkan atau artikel memakai tarif upah borongan (upah dihitung dari hasil kerja)." />
                <x-form.input name="overhead_cost" label="Overhead per pcs (Rp)" :value="$bom->overhead_cost" data-money inputmode="numeric" help="mis. listrik, penyusutan mesin, sewa per pcs." tip="Biaya tidak langsung yang dibebankan per pcs (listrik, sewa, penyusutan mesin). Ikut menambah HPP setiap SPK." />
                <x-form.textarea name="notes" label="Catatan" :value="$bom->notes" class="sm:col-span-2" rows="2" />
                <x-form.checkbox name="is_active" label="Jadikan BOM aktif (BOM lain untuk produk ini akan dinonaktifkan)" tip="Hanya BOM aktif yang dipakai SPK baru dan MRP. Produk tanpa BOM aktif tidak bisa dibuatkan SPK." :checked="$bom->is_active" class="sm:col-span-2" />
            </div>
        </x-card>

        <x-card title="Kebutuhan Bahan per 1 pcs" :padding="false" tip="Isi pemakaian bahan untuk satu potong produk jadi. Saat SPK dibuat, angka ini dikalikan jumlah pcs (+ waste).">
            <x-slot:header><button type="button" class="btn-secondary btn-sm" @click="add()">+ Bahan</button></x-slot:header>
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th class="min-w-72">Bahan</th><th class="w-40">Qty / pcs <x-tip title="Qty per pcs" text="Jumlah bahan untuk 1 pcs dalam satuan bahan, mis. 1,6 (meter kain) atau 7 (kancing)." /></th><th class="w-32">Waste % <x-tip title="Waste (susut)" text="Cadangan bahan yang terbuang saat potong/jahit. Mis. 5 berarti kebutuhan dinaikkan 5%: 1,6 m → 1,68 m per pcs." /></th><th>Keterangan</th><th class="w-12"></th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-for="(row, index) in rows" :key="row.key">
                            <tr>
                                <td>
                                    <select :name="`items[${index}][material_id]`" class="form-control" required data-select data-placeholder="Pilih bahan..."
                                            x-init="$el.value = row.material_id; $nextTick(() => ERP.initPlugins($el))"
                                            @change="row.material_id = $event.target.value">
                                        <option value="">Pilih bahan...</option>
                                        @foreach ($materials as $m)
                                            <option value="{{ $m->id }}">{{ $m->display_name }} [{{ $m->unit->code }}]</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td><input type="number" step="0.0001" min="0" :name="`items[${index}][quantity]`" x-model="row.quantity" class="form-control" required></td>
                                <td><input type="number" step="0.01" min="0" max="100" :name="`items[${index}][waste_percent]`" x-model="row.waste_percent" class="form-control"></td>
                                <td><input type="text" :name="`items[${index}][notes]`" x-model="row.notes" class="form-control" placeholder="mis. badan + lengan"></td>
                                <td class="text-right"><button type="button" class="text-xl text-red-500 hover:text-red-700" @click="remove(index)">&times;</button></td>
                            </tr>
                        </template>
                        <tr x-show="rows.length === 0"><td colspan="5" class="py-8 text-center text-gray-500">Belum ada bahan.</td></tr>
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="flex justify-end gap-2">
            <a href="{{ route('boms.index') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary">Simpan BOM</button>
        </div>
    </form>
    @include('partials.line-items-script')
</x-layouts.app>
