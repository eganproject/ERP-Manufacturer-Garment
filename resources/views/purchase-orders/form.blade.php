@php
    $initialItems = old('items', $order->items->map(fn ($i) => [
        'material_id' => $i->material_id, 'unit_id' => $i->unit_id ?? $i->material?->unit_id, 'quantity' => (float) $i->quantity, 'price' => (float) $i->price,
    ])->all());
    // Pilihan satuan per bahan: satuan beli (default) + satuan stok, dengan faktor ke satuan stok
    $unitMap = $materials->mapWithKeys(fn ($m) => [$m->id => [
        'default' => $m->defaultPurchaseUnitId(),
        'stock' => $m->unit?->code,
        'options' => collect($m->purchaseUnitOptions())->map(fn ($o, $id) => ['id' => $id, 'code' => $o[0], 'factor' => $o[1]])->values(),
    ]]);
    // Harga standar bahan per satuan stok → per satuan beli default
    $defaultPrices = $materials->mapWithKeys(fn ($m) => [$m->id => round((float) $m->price * $m->conversionFor($m->defaultPurchaseUnitId()), 2)]);
@endphp

<x-layouts.app :title="$order->exists ? 'Ubah PO '.$order->number : 'Buat Purchase Order'">
    <x-guide title="Alur Purchase Order (PO)" :steps="['Isi supplier, tanggal dan daftar bahan yang dipesan, lalu **Simpan PO** (status Draft, masih bisa diubah).', 'Di halaman PO klik **Tandai Dipesan**. Bila nilainya di atas batas persetujuan, PO harus **disetujui** Keuangan/Admin dulu.', 'Saat barang datang, Gudang klik **Terima Barang** (boleh bertahap). Stok & harga pokok bahan otomatis bertambah.', 'Keuangan mencatat **Tagihan Supplier** dari barang yang diterima, lalu membayarnya lewat Pembayaran Supplier.']"></x-guide>
    <form method="POST" action="{{ $order->exists ? route('purchase-orders.update', $order) : route('purchase-orders.store') }}"
          x-data="Object.assign(lineItems(@js(array_values($initialItems)), { material_id: '', unit_id: '', quantity: '', price: '' }, @js($defaultPrices)), purchaseUnits(@js($unitMap)))"
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
            <x-slot:header><button type="button" class="btn-secondary btn-sm" @click="add()">+ Bahan</button></x-slot:header>
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th class="min-w-72">Bahan</th><th class="w-36">Qty <x-tip title="Qty" text="Jumlah yang dipesan dalam satuan yang dipilih di kolom Satuan (mis. YD sesuai nota supplier). Stok otomatis dicatat dalam satuan stok bahan." /></th><th class="w-28">Satuan <x-tip title="Satuan beli" text="Satuan di PO & nota supplier. Bila bahan punya satuan beli (mis. YD) dengan satuan stok berbeda (mis. M), qty & harga dikonversi otomatis saat barang diterima.\nAtur di Master Bahan → Satuan Beli." /></th><th class="w-44">Harga (Rp) <x-tip title="Harga" text="Harga beli per satuan yang dipilih (mis. per YD) sesuai kesepakatan dengan supplier. Terisi dari harga beli standar bahan dan boleh diubah. Harga ini menjadi dasar harga pokok bahan saat diterima." /></th><th class="w-44 text-right">Subtotal</th><th class="w-12"></th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-for="(row, index) in rows" :key="row.key">
                            <tr>
                                <td>
                                    <select :name="`items[${index}][material_id]`" class="form-control" required data-select data-placeholder="Pilih bahan..."
                                            x-init="$el.value = row.material_id; $nextTick(() => ERP.initPlugins($el))"
                                            @change="row.material_id = $event.target.value; row.unit_id = defaultUnit(row.material_id); pick(row, row.material_id, $el.closest('tr').querySelector('[data-money]'))">
                                        <option value="">Pilih bahan...</option>
                                        @foreach ($materials as $m)
                                            <option value="{{ $m->id }}">{{ $m->display_name }} [{{ $m->unit->code }}{{ $m->hasPurchaseUnit() ? " · beli ".$m->purchaseUnit?->code : "" }}]</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <input type="number" step="0.01" min="0" :name="`items[${index}][quantity]`" x-model="row.quantity" class="form-control" required>
                                    <div class="mt-1 text-xs text-gray-500" x-show="factor(row) !== 1" x-text="stockHint(row)"></div>
                                </td>
                                <td>
                                    <template x-if="unitOptions(row).length > 1">
                                        <select :name="`items[${index}][unit_id]`" class="form-control"
                                                @change="changeUnit(row, $event.target.value, $el.closest('tr').querySelector('[data-money]'))">
                                            <template x-for="opt in unitOptions(row)" :key="opt.id">
                                                <option :value="opt.id" x-text="opt.code" :selected="String(opt.id) === String(row.unit_id)"></option>
                                            </template>
                                        </select>
                                    </template>
                                    <template x-if="unitOptions(row).length <= 1">
                                        <div>
                                            <input type="hidden" :name="`items[${index}][unit_id]`" :value="row.unit_id">
                                            <span class="block py-1.5 text-sm text-gray-600" x-text="unitOptions(row)[0]?.code ?? '-'"></span>
                                        </div>
                                    </template>
                                </td>
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
                        <tr class="bg-gray-50 font-semibold"><td colspan="4" class="px-4 py-3 text-right">Total</td><td class="px-4 py-3 text-right" x-text="money(total)"></td><td></td></tr>
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
    <script>
        /** Satuan beli per baris PO: pilihan satuan, faktor ke satuan stok, dan konversi harga saat satuan diganti. */
        function purchaseUnits(units) {
            return {
                units,
                unitOptions(row) {
                    return this.units[row.material_id]?.options ?? [];
                },
                defaultUnit(materialId) {
                    return this.units[materialId]?.default ?? '';
                },
                factor(row) {
                    const opt = this.unitOptions(row).find(o => String(o.id) === String(row.unit_id));
                    return opt ? Number(opt.factor) : 1;
                },
                stockHint(row) {
                    const stock = (Number(row.quantity) || 0) * this.factor(row);
                    return '≈ ' + ERP.formatNumber(Math.round(stock * 100) / 100, 2) + ' ' + (this.units[row.material_id]?.stock ?? '') + ' di stok';
                },
                changeUnit(row, unitId, priceInput) {
                    const before = this.factor(row);
                    row.unit_id = unitId;
                    const after = this.factor(row);
                    if (Number(row.price) > 0 && before !== after) {
                        row.price = Math.round(Number(row.price) / before * after * 100) / 100;
                        ERP.setMoney(priceInput, row.price);
                    }
                },
            };
        }
    </script>
</x-layouts.app>
