@php
    $initialItems = old('items', $order->items->map(fn ($i) => [
        'product_variant_id' => $i->product_variant_id, 'quantity' => (float) $i->quantity, 'price' => (float) $i->price,
    ])->all());
@endphp

<x-layouts.app :title="$order->exists ? 'Ubah SO '.$order->number : 'Buat Sales Order'">
    <x-guide title="Alur Sales Order (SO)" :steps="['Pilih pelanggan dan isi produk per ukuran/varian, qty dan harga, lalu simpan (status Draft).', 'Periksa lalu klik **Konfirmasi**: SO terkunci, masuk perhitungan MRP dan bisa dibuatkan **SPK produksi**.', 'Setelah barang siap, Gudang klik **Kirim Barang** untuk membuat surat jalan (boleh bertahap).', 'Keuangan membuat **Invoice** dari barang yang sudah dikirim, lalu mencatat pembayaran pelanggan.']"></x-guide>
    <form method="POST" action="{{ $order->exists ? route('sales-orders.update', $order) : route('sales-orders.store') }}"
          x-data="lineItems(@js(array_values($initialItems)), { product_variant_id: '', quantity: '', price: '' }, @js($variants->mapWithKeys(fn ($v) => [$v->id => $v->selling_price])))"
          x-init="discount = @js((float) old('discount', $order->discount))"
          class="space-y-6">
        @csrf
        @if ($order->exists) @method('PUT') @endif

        <x-card title="Informasi SO">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <x-form.select name="customer_id" label="Pelanggan" tip="Pembeli pesanan ini. Termin pembayaran pelanggan menentukan jatuh tempo invoice." :options="$customers->mapWithKeys(fn ($c) => [$c->id => $c->code.' - '.$c->name])" :value="$order->customer_id" required data-select />
                <x-form.input name="order_date" label="Tanggal Order" tip="Tanggal pesanan diterima dari pelanggan. Dipakai di laporan penjualan." :value="$order->order_date" required data-date />
                <x-form.input name="delivery_date" label="Target Kirim" tip="Tanggal yang dijanjikan ke pelanggan. Dipakai sebagai patokan target selesai SPK dan untuk menandai pesanan yang terlambat." :value="$order->delivery_date" data-date />
                <x-form.textarea name="notes" label="Catatan" :value="$order->notes" rows="2" class="md:col-span-3" />
            </div>
        </x-card>

        <x-card title="Produk Dipesan" :padding="false">
            <x-slot:header><button type="button" class="btn-secondary py-1.5" @click="add()">+ Produk</button></x-slot:header>
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th class="min-w-80">Produk / Varian</th><th class="w-32">Qty <x-tip title="Qty" text="Jumlah pcs yang dipesan untuk varian ini." /></th><th class="w-44">Harga (Rp) <x-tip title="Harga" text="Harga jual per pcs. Terisi dari harga jual produk/varian dan boleh diubah sesuai kesepakatan." /></th><th class="w-44 text-right">Subtotal</th><th class="w-12"></th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-for="(row, index) in rows" :key="row.key">
                            <tr>
                                <td>
                                    <select :name="`items[${index}][product_variant_id]`" class="form-control" required data-select data-placeholder="Cari produk / SKU..."
                                            x-init="$el.value = row.product_variant_id; $nextTick(() => ERP.initPlugins($el))"
                                            @change="row.product_variant_id = $event.target.value; pick(row, row.product_variant_id, $el.closest('tr').querySelector('[data-money]'))">
                                        <option value="">Cari produk / SKU...</option>
                                        @foreach ($variants as $v)
                                            <option value="{{ $v->id }}">{{ $v->sku }} · {{ $v->display_name }} (stok {{ qty($v->stock) }})</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td><input type="number" step="1" min="0" :name="`items[${index}][quantity]`" x-model="row.quantity" class="form-control" required></td>
                                <td>
                                    <input type="text" :name="`items[${index}][price]`" class="form-control" inputmode="numeric" required data-money
                                           x-init="$el.value = row.price; $nextTick(() => ERP.initPlugins($el))" @money-change="row.price = $event.detail">
                                </td>
                                <td class="text-right font-medium" x-text="money(subtotal(row))"></td>
                                <td class="text-right"><button type="button" class="text-xl text-red-500 hover:text-red-700" @click="remove(index)">&times;</button></td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr><td colspan="3" class="px-4 py-2 text-right">Subtotal</td><td class="px-4 py-2 text-right font-medium" x-text="money(total)"></td><td></td></tr>
                        <tr>
                            <td colspan="3" class="px-4 py-2 text-right">Diskon (Rp)</td>
                            <td class="px-4 py-2"><input type="text" name="discount" class="form-control text-right" inputmode="numeric" data-money value="{{ (float) old('discount', $order->discount) }}" @money-change="discount = $event.detail"></td>
                            <td></td>
                        </tr>
                        <tr class="font-semibold"><td colspan="3" class="px-4 py-3 text-right">Total</td><td class="px-4 py-3 text-right" x-text="money(grandTotal)"></td><td></td></tr>
                    </tfoot>
                </table>
            </div>
        </x-card>

        <div class="flex justify-end gap-2">
            <a href="{{ $order->exists ? route('sales-orders.show', $order) : route('sales-orders.index') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary">Simpan SO</button>
        </div>
    </form>
    @include('partials.line-items-script')
</x-layouts.app>
