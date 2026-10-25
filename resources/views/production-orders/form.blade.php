@php
    $productData = $products->map(fn ($p) => [
        'id' => $p->id,
        'name' => $p->name,
        'bom' => $p->activeBom?->name,
        'variants' => $p->variants->map(fn ($v) => ['id' => $v->id, 'sku' => $v->sku, 'size' => $v->size, 'color' => $v->color])->values(),
        'materials' => $p->activeBom?->items->map(fn ($i) => [
            'name' => $i->material->display_name,
            'unit' => $i->material->unit->code,
            'factor' => (float) $i->quantity * (1 + (float) $i->waste_percent / 100),
            'stock' => (float) $i->material->stock,
        ])->values() ?? [],
    ])->keyBy('id');

    // Saran qty dari SO = sisa yang belum dibuatkan SPK lain
    $soData = $soRemaining->map(fn ($rows) => (object) array_filter($rows, fn ($q) => $q > 0));
    $initialQty = old('items', $existingItems->map(fn ($q) => (float) $q)->all());
@endphp

<x-layouts.app :title="$order->exists ? 'Ubah SPK '.$order->number : 'Buat SPK Produksi'">
    <form method="POST" action="{{ $order->exists ? route('production-orders.update', $order) : route('production-orders.store') }}"
          x-data="productionForm(@js($productData), @js($soData), @js((object) $initialQty))"
          x-init="productId = @js((string) old('product_id', $order->product_id ?? '')); soId = @js((string) old('sales_order_id', $order->sales_order_id ?? '')); if (soId && !Object.keys(qty).length) fillFromSo()"
          class="space-y-6">
        @csrf
        @if ($order->exists) @method('PUT') @endif

        <x-card title="Informasi SPK">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <x-form.select name="product_id" label="Produk" :options="$products->mapWithKeys(fn ($p) => [$p->id => $p->code.' - '.$p->name])" :value="$order->product_id" required data-select
                               help="Hanya produk yang memiliki BOM aktif." x-on:change="productId = $event.target.value; fillFromSo()" />
                <x-form.select name="sales_order_id" label="Untuk Sales Order (opsional)" :options="$salesOrders->mapWithKeys(fn ($so) => [$so->id => $so->number.' - '.$so->customer->name])" :value="$order->sales_order_id" data-select
                               placeholder="-- Stok / tanpa SO --" x-on:change="soId = $event.target.value; fillFromSo()" />
                <x-form.input name="start_date" label="Tanggal Mulai" :value="$order->start_date" required data-date />
                <x-form.input name="due_date" label="Target Selesai" :value="$order->due_date" data-date />
                <x-form.textarea name="notes" label="Catatan" :value="$order->notes" rows="2" class="md:col-span-2" />
            </div>
        </x-card>

        <x-card title="Jumlah per Ukuran / Varian" :padding="false">
            <template x-if="!product">
                <p class="p-6 text-center text-sm text-gray-500">Pilih produk terlebih dahulu.</p>
            </template>
            <template x-if="product">
                <div class="overflow-x-auto">
                    <table class="table-base">
                        <thead><tr><th>SKU</th><th>Ukuran</th><th>Warna</th><th class="w-40">Qty (pcs)</th></tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            <template x-for="v in product.variants" :key="v.id">
                                <tr>
                                    <td class="font-medium" x-text="v.sku"></td>
                                    <td x-text="v.size"></td>
                                    <td x-text="v.color || '-'"></td>
                                    <td><input type="number" min="0" step="1" :name="`items[${v.id}]`" x-model="qty[v.id]" class="form-control"></td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot><tr class="bg-gray-50 font-semibold"><td colspan="3" class="px-4 py-3 text-right">Total</td><td class="px-4 py-3" x-text="ERP.formatNumber(totalQty) + ' pcs'"></td></tr></tfoot>
                    </table>
                </div>
            </template>
        </x-card>

        <x-card title="Perkiraan Kebutuhan Bahan" :padding="false" x-show="product && totalQty > 0">
            <x-slot:header><span class="text-xs text-gray-500" x-text="product ? 'Berdasarkan ' + product.bom : ''"></span></x-slot:header>
            <table class="table-base">
                <thead><tr><th>Bahan</th><th class="text-right">Kebutuhan</th><th class="text-right">Stok Gudang</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    <template x-for="m in (product ? product.materials : [])" :key="m.name">
                        <tr>
                            <td x-text="m.name"></td>
                            <td class="text-right font-medium" x-text="ERP.formatNumber(m.factor * totalQty, 2) + ' ' + m.unit"></td>
                            <td class="text-right" :class="m.stock < m.factor * totalQty ? 'text-red-600 font-semibold' : ''" x-text="ERP.formatNumber(m.stock, 2) + ' ' + m.unit"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </x-card>

        <div class="flex justify-end gap-2">
            <a href="{{ $order->exists ? route('production-orders.show', $order) : route('production-orders.index') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary">Simpan SPK</button>
        </div>
    </form>

    <script>
        function productionForm(products, salesOrders, initialQty) {
            return {
                products,
                salesOrders,
                productId: '',
                soId: '',
                qty: { ...initialQty },
                get product() {
                    return this.products[this.productId] || null;
                },
                get totalQty() {
                    if (!this.product) return 0;
                    return this.product.variants.reduce((sum, v) => sum + (Number(this.qty[v.id]) || 0), 0);
                },
                fillFromSo() {
                    const so = this.salesOrders[this.soId];
                    if (!so || !this.product) return;
                    const filled = {};
                    this.product.variants.forEach(v => { if (so[v.id]) filled[v.id] = so[v.id]; });
                    if (Object.keys(filled).length) this.qty = filled;
                },
            };
        }
    </script>
</x-layouts.app>
