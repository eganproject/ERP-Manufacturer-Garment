@php
    $initialItems = old('items', $quotation->items->map(fn ($i) => [
        'product_variant_id' => $i->product_variant_id, 'description' => $i->description, 'quantity' => (float) $i->quantity, 'price' => (float) $i->price,
    ])->all());
@endphp

<x-layouts.app :title="$quotation->exists ? 'Ubah Penawaran '.$quotation->number : 'Buat Penawaran Harga'">
    <x-guide title="Alur Penawaran Harga" :steps="['Isi pelanggan, perihal, produk, harga, diskon dan PPN, lalu simpan sebagai Draft.', '**Cetak** penawaran (PDF) dan kirim ke pelanggan, lalu klik **Tandai Terkirim**.', 'Bila pelanggan menawar, klik **Buat Revisi** (nomor -R1, -R2). Penawaran lama otomatis berstatus Direvisi.', 'Bila setuju, klik **Jadikan Sales Order**: SO draft terbentuk dengan isi yang sama.']"></x-guide>
    <form method="POST" action="{{ $quotation->exists ? route('quotations.update', $quotation) : route('quotations.store') }}"
          x-data="lineItems(@js(array_values($initialItems)), { product_variant_id: '', description: '', quantity: '', price: '' }, @js($variants->mapWithKeys(fn ($v) => [$v->id => $v->selling_price])))"
          x-init="discount = @js((float) old('discount', $quotation->discount))"
          class="space-y-6">
        @csrf
        @if ($quotation->exists) @method('PUT') @endif

        <x-card title="Informasi Penawaran">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <x-form.select name="customer_id" label="Pelanggan" :options="$customers->mapWithKeys(fn ($c) => [$c->id => $c->code.' - '.$c->name])" :value="$quotation->customer_id" required data-select class="md:col-span-2" />
                <x-form.input name="quotation_date" label="Tanggal Penawaran" :value="$quotation->quotation_date" required data-date />
                <x-form.input name="valid_until" label="Berlaku Sampai" tip="Batas waktu harga ini berlaku. Lewat tanggal ini penawaran tampil Kedaluwarsa dan harus direvisi sebelum dijadikan SO." :value="$quotation->valid_until" data-date help="Kosong = sesuai pengaturan." />
                <x-form.input name="subject" label="Perihal" tip="Judul singkat penawaran yang dicetak di PDF, mis. nama proyek atau acara pelanggan." :value="$quotation->subject" placeholder="mis. Seragam kaos event 2026" class="md:col-span-2" />
                <x-form.input name="lead_time_days" type="number" min="0" label="Estimasi Selesai (hari)" :value="$quotation->lead_time_days" help="Dipakai sebagai target kirim SO." tip="Perkiraan lama pengerjaan sejak pesanan disetujui. Saat dijadikan SO, target kirim = tanggal order + jumlah hari ini." />
                <div></div>
                <x-form.textarea name="terms" label="Syarat & Ketentuan" tip="Dicetak di penawaran: mis. DP 50%, harga belum termasuk ongkir, minimal order. Isi awal diambil dari Pengaturan dan boleh diubah per penawaran." :value="$quotation->terms" rows="3" class="md:col-span-2" />
                <x-form.textarea name="notes" label="Catatan Internal" tip="Catatan untuk tim sendiri. Tidak dicetak di dokumen penawaran." :value="$quotation->notes" rows="3" class="md:col-span-2" />
            </div>
        </x-card>

        <x-card title="Produk Ditawarkan" :padding="false">
            <x-slot:header><button type="button" class="btn-secondary py-1.5" @click="add()">+ Produk</button></x-slot:header>
            <div class="overflow-x-auto" x-data="{ tax: {{ (float) old('tax_rate', $quotation->tax_rate) }} }">
                <table class="table-base">
                    <thead><tr><th class="min-w-72">Produk / Varian</th><th class="min-w-48">Keterangan <x-tip title="Keterangan" text="Detail tambahan per baris yang dicetak, mis. sablon 2 warna, bordir logo dada kiri." /></th><th class="w-28">Qty</th><th class="w-40">Harga (Rp)</th><th class="w-40 text-right">Subtotal</th><th class="w-12"></th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-for="(row, index) in rows" :key="row.key">
                            <tr>
                                <td>
                                    <select :name="`items[${index}][product_variant_id]`" class="form-control" required data-select data-placeholder="Cari produk / SKU..."
                                            x-init="$el.value = row.product_variant_id; $nextTick(() => ERP.initPlugins($el))"
                                            @change="row.product_variant_id = $event.target.value; pick(row, row.product_variant_id, $el.closest('tr').querySelector('[data-money]'))">
                                        <option value="">Cari produk / SKU...</option>
                                        @foreach ($variants as $v)
                                            <option value="{{ $v->id }}">{{ $v->sku }} · {{ $v->display_name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td><input type="text" :name="`items[${index}][description]`" x-model="row.description" class="form-control" placeholder="Sablon, bordir, dll."></td>
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
                        <tr><td colspan="4" class="px-4 py-2 text-right">Subtotal</td><td class="px-4 py-2 text-right font-medium" x-text="money(total)"></td><td></td></tr>
                        <tr>
                            <td colspan="4" class="px-4 py-2 text-right">Diskon (Rp) <x-tip title="Diskon" text="Potongan harga untuk seluruh penawaran, dalam rupiah. Mengurangi subtotal sebelum PPN." /></td>
                            <td class="px-4 py-2"><input type="text" name="discount" class="form-control text-right" inputmode="numeric" data-money value="{{ (float) old('discount', $quotation->discount) }}" @money-change="discount = $event.detail"></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td colspan="4" class="px-4 py-2 text-right">PPN (%) <x-tip title="PPN" text="Isi 11 (atau tarif berlaku) bila perusahaan PKP dan menagih PPN. Isi 0 bila tidak." /></td>
                            <td class="px-4 py-2"><input type="number" step="0.01" min="0" max="100" name="tax_rate" x-model.number="tax" class="form-control text-right"></td>
                            <td></td>
                        </tr>
                        <tr><td colspan="4" class="px-4 py-2 text-right">Nilai PPN</td><td class="px-4 py-2 text-right" x-text="money(Math.round(grandTotal * (Number(tax) || 0) / 100))"></td><td></td></tr>
                        <tr class="font-semibold"><td colspan="4" class="px-4 py-3 text-right">Total Penawaran</td><td class="px-4 py-3 text-right" x-text="money(grandTotal + Math.round(grandTotal * (Number(tax) || 0) / 100))"></td><td></td></tr>
                    </tfoot>
                </table>
            </div>
        </x-card>

        <div class="flex justify-end gap-2">
            <a href="{{ $quotation->exists ? route('quotations.show', $quotation) : route('quotations.index') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary">Simpan Penawaran</button>
        </div>
    </form>
    @include('partials.line-items-script')
</x-layouts.app>
