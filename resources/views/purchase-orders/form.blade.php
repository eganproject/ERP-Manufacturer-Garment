@php
    $initialItems = old('items', $order->items->map(fn ($i) => [
        'material_id' => $i->material_id, 'quantity' => (float) $i->quantity, 'price' => (float) $i->price,
    ])->all());
@endphp

<x-layouts.app :title="$order->exists ? 'Ubah PO '.$order->number : 'Buat Purchase Order'">
    <x-guide title="Alur Purchase Order (PO)" :steps="['Isi supplier, tanggal dan daftar bahan yang dipesan, lalu **Simpan PO** (status Draft, masih bisa diubah).', 'Di halaman PO klik **Tandai Dipesan**. Bila nilainya di atas batas persetujuan, PO harus **disetujui** Keuangan/Admin dulu.', 'Saat barang datang, Gudang klik **Terima Barang** (boleh bertahap). Stok & harga pokok bahan otomatis bertambah.', 'Keuangan mencatat **Tagihan Supplier** dari barang yang diterima, lalu membayarnya lewat Pembayaran Supplier.']"></x-guide>
    <form method="POST" action="{{ $order->exists ? route('purchase-orders.update', $order) : route('purchase-orders.store') }}"
          x-data="lineItems(@js(array_values($initialItems)), { material_id: '', quantity: '', price: '' }, @js($materials->pluck('price', 'id')->map(fn ($p) => (float) $p)))"
          class="space-y-6">
        @csrf
        @if ($order->exists) @method('PUT') @endif

        <x-card title="Informasi PO">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <x-form.select name="supplier_id" label="Supplier" tip="Pemasok tempat bahan dibeli. Termin pembayaran supplier menentukan jatuh tempo tagihannya nanti." :options="$suppliers->mapWithKeys(fn ($s) => [$s->id => $s->code.' - '.$s->name])" :value="$order->supplier_id" required data-select />
                <x-form.input name="order_date" label="Tanggal PO" tip="Tanggal pesanan dibuat. Dipakai di laporan pembelian dan nomor dokumen." :value="$order->order_date" required data-date />
                <x-form.input name="expected_date" label="Estimasi Barang Datang" tip="Perkiraan tanggal bahan tiba. Membantu gudang & produksi merencanakan jadwal; PO yang lewat tanggal ini ditandai terlambat." :value="$order->expected_date" data-date />
                <x-form.textarea name="notes" label="Catatan" :value="$order->notes" rows="2" class="md:col-span-3" />
            </div>
        </x-card>

        <x-card title="Daftar Bahan" :padding="false">
            <x-slot:header><button type="button" class="btn-secondary py-1.5" @click="add()">+ Bahan</button></x-slot:header>
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th class="min-w-72">Bahan</th><th class="w-36">Qty <x-tip title="Qty" text="Jumlah yang dipesan dalam satuan bahan (lihat kode satuan di nama bahan)." /></th><th class="w-44">Harga (Rp) <x-tip title="Harga" text="Harga beli per satuan sesuai kesepakatan dengan supplier. Terisi dari harga beli standar bahan dan boleh diubah. Harga ini menjadi dasar harga pokok bahan saat diterima." /></th><th class="w-44 text-right">Subtotal</th><th class="w-12"></th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-for="(row, index) in rows" :key="row.key">
                            <tr>
                                <td>
                                    <select :name="`items[${index}][material_id]`" class="form-control" required data-select data-placeholder="Pilih bahan..."
                                            x-init="$el.value = row.material_id; $nextTick(() => ERP.initPlugins($el))"
                                            @change="row.material_id = $event.target.value; pick(row, row.material_id, $el.closest('tr').querySelector('[data-money]'))">
                                        <option value="">Pilih bahan...</option>
                                        @foreach ($materials as $m)
                                            <option value="{{ $m->id }}">{{ $m->display_name }} [{{ $m->unit->code }}]</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td><input type="number" step="0.01" min="0" :name="`items[${index}][quantity]`" x-model="row.quantity" class="form-control" required></td>
                                <td>
                                    <input type="text" :name="`items[${index}][price]`" class="form-control" inputmode="numeric" required data-money
                                           x-init="$el.value = row.price; $nextTick(() => ERP.initPlugins($el))" @money-change="row.price = $event.detail">
                                </td>
                                <td class="text-right font-medium" x-text="money(subtotal(row))"></td>
                                <td class="text-right"><button type="button" class="text-xl text-red-500 hover:text-red-700" @click="remove(index)">&times;</button></td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot>
                        <tr class="bg-gray-50 font-semibold"><td colspan="3" class="px-4 py-3 text-right">Total</td><td class="px-4 py-3 text-right" x-text="money(total)"></td><td></td></tr>
                    </tfoot>
                </table>
            </div>
        </x-card>

        <div class="flex justify-end gap-2">
            <a href="{{ $order->exists ? route('purchase-orders.show', $order) : route('purchase-orders.index') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary">Simpan PO</button>
        </div>
    </form>
    @include('partials.line-items-script')
</x-layouts.app>
