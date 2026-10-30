<x-layouts.app title="Catat Tagihan Supplier">
    <x-guide title="Cara mencatat tagihan supplier" :steps="['Pilih PO yang barangnya **sudah diterima** gudang, atau order maklon yang hasilnya sudah diterima.', 'Salin nomor faktur, tanggal, harga, diskon dan PPN sesuai nota/faktur dari supplier.', 'Setelah disimpan, hutang supplier tercatat dan bisa dibayar lewat **Pembayaran Supplier** sebelum jatuh tempo.']"></x-guide>
    <x-card title="1. Pilih Purchase Order" class="mb-6" tip="Tagihan dibuat dari barang yang sudah diterima, supaya kita hanya membayar barang yang benar-benar datang.">
        <form method="GET" action="{{ route('bills.create') }}" class="flex flex-wrap items-end gap-2">
            <div class="w-full sm:w-[28rem]">
                <x-form.select name="purchase_order_id" :value="$order?->id" placeholder="Pilih PO yang barangnya sudah diterima..." data-select
                    :options="$billableOrders->mapWithKeys(fn ($o) => [$o->id => $o->number.' · '.$o->supplier->name])" />
            </div>
            <button type="submit" class="btn-secondary">Tampilkan</button>
        </form>
        @if ($billableOrders->isEmpty())
            <p class="mt-3 text-sm text-gray-500">Belum ada PO dengan barang diterima yang belum ditagih.</p>
        @endif
        @if ($billableSubcontracts->isNotEmpty())
            <form method="GET" action="{{ route('bills.create') }}" class="mt-4 flex flex-wrap items-end gap-2 border-t border-gray-100 pt-4">
                <div class="w-full sm:w-[28rem]">
                    <x-form.select name="subcontract_order_id" label="Atau tagihan jasa maklon" placeholder="Pilih order maklon yang hasilnya belum ditagih..." data-select
                        :options="$billableSubcontracts->mapWithKeys(fn ($o) => [$o->id => $o->number.' · '.$o->supplier->name])" />
                </div>
                <button type="submit" class="btn-secondary">Tampilkan</button>
            </form>
        @endif
    </x-card>

    @if ($order)
        @php
            $lines = $order->items->filter(fn ($i) => $i->unbilledQuantity() > 0)->map(fn ($i) => [
                'id' => $i->id,
                'qty' => (float) old("items.{$i->id}.quantity", $i->unbilledQuantity()),
                'price' => (float) old("items.{$i->id}.price", $i->price),
            ])->values();
        @endphp
        <form method="POST" action="{{ route('bills.store') }}" class="space-y-6"
              x-data="{ lines: @js($lines), discount: {{ (float) old('discount', 0) }}, tax: {{ (float) old('tax_rate', 0) }},
                        get subtotal() { return this.lines.reduce((s, l) => s + (Number(l.qty) || 0) * (Number(l.price) || 0), 0) },
                        get taxAmount() { return Math.round(Math.max(this.subtotal - this.discount, 0) * this.tax / 100) },
                        get total() { return Math.max(this.subtotal - this.discount, 0) + this.taxAmount } }"
              data-confirm="Tagihan supplier akan dicatat sebagai hutang." data-confirm-icon="question" data-confirm-button="Ya, simpan">
            @csrf
            <input type="hidden" name="purchase_order_id" value="{{ $order->id }}">

            <x-card title="2. Informasi Tagihan">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <div>
                        <span class="form-label">Supplier</span>
                        <p class="py-2 text-sm font-medium">{{ $order->supplier->name }}</p>
                        <p class="text-xs text-gray-500">Termin {{ $order->supplier->payment_terms }} hari</p>
                    </div>
                    <x-form.input name="supplier_invoice_number" label="No. Faktur Supplier" tip="Nomor invoice/nota yang tertulis di dokumen supplier. Dipakai untuk mencocokkan saat membayar dan mencegah tagihan tercatat dua kali." placeholder="Nomor di invoice supplier" />
                    <x-form.input name="bill_date" label="Tanggal Faktur" tip="Tanggal yang tertera di faktur supplier. Hutang diakui pada tanggal ini." :value="now()" required data-date />
                    <x-form.input name="due_date" label="Jatuh Tempo" :value="now()->addDays($order->supplier->payment_terms)" data-date help="Default: tanggal faktur + termin supplier." tip="Batas waktu kita harus membayar. Tagihan yang lewat tanggal ini tampil terlambat di laporan umur hutang dan dashboard." />
                    <x-form.input name="tax_invoice_number" label="No. Faktur Pajak" placeholder="Bila tagihan ber-PPN" help="Dipakai di laporan PPN masukan." tip="Nomor faktur pajak dari supplier/vendor (13–20 digit, mis. 010.000-26.00000001). Wajib diisi agar PPN tagihan ini bisa dikreditkan sebagai PPN masukan di Laporan PPN." />
                    <x-form.input name="tax_invoice_date" label="Tgl Faktur Pajak" data-date help="Kosong = tanggal tagihan." />
                    <x-form.textarea name="notes" label="Catatan" rows="2" class="md:col-span-2" />
                </div>
            </x-card>

            <x-card title="3. Barang yang Ditagih" :padding="false">
                <div class="overflow-x-auto">
                    <table class="table-base">
                        <thead><tr><th>Bahan</th><th class="text-right">Dipesan</th><th class="text-right">Diterima</th><th class="text-right">Sudah Ditagih</th><th class="text-right">Bisa Ditagih</th><th class="w-36">Qty Tagih <x-tip title="Qty tagih" text="Jumlah yang ditagih supplier di faktur ini, maksimal sebesar kolom Bisa Ditagih." /></th><th class="w-44">Harga Faktur <x-tip title="Harga faktur" text="Harga per satuan sesuai faktur supplier. Bila berbeda dengan harga PO, selisihnya otomatis dijurnal ke akun Selisih Harga Pembelian." /></th><th class="text-right">Subtotal</th></tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($order->items->filter(fn ($i) => $i->unbilledQuantity() > 0)->values() as $idx => $item)
                                <tr>
                                    <td>{{ $item->material->display_name }}<div class="text-xs text-gray-500">Harga PO {{ rupiah($item->price) }} / {{ $item->unitCode() }}</div></td>
                                    <td class="text-right">{{ qty($item->quantity) }} {{ $item->unitCode() }}</td>
                                    <td class="text-right">{{ qty($item->received_quantity) }}</td>
                                    <td class="text-right">{{ qty($item->billed_quantity) }}</td>
                                    <td class="text-right font-medium">{{ qty($item->unbilledQuantity()) }}</td>
                                    <td><input type="number" step="any" min="0" max="{{ $item->unbilledQuantity() }}" name="items[{{ $item->id }}][quantity]" x-model.number="lines[{{ $idx }}].qty" class="form-control"></td>
                                    <td><input type="number" step="any" min="0" name="items[{{ $item->id }}][price]" x-model.number="lines[{{ $idx }}].price" class="form-control"></td>
                                    <td class="text-right whitespace-nowrap" x-text="'Rp ' + ERP.formatNumber((lines[{{ $idx }}].qty || 0) * (lines[{{ $idx }}].price || 0))"></td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr><td colspan="7" class="px-4 py-2 text-right">Subtotal</td><td class="px-4 py-2 text-right whitespace-nowrap" x-text="'Rp ' + ERP.formatNumber(subtotal)"></td></tr>
                            <tr>
                                <td colspan="7" class="px-4 py-2 text-right">Diskon (Rp) <x-tip title="Diskon" text="Potongan harga dari supplier untuk seluruh faktur, dalam rupiah." /></td>
                                <td class="px-4 py-2"><input type="number" step="any" min="0" name="discount" x-model.number="discount" class="form-control text-right"></td>
                            </tr>
                            <tr>
                                <td colspan="7" class="px-4 py-2 text-right">PPN (%) <x-tip title="PPN" text="Tarif PPN di faktur supplier (mis. 11). Isi 0 bila supplier tidak memungut PPN." /></td>
                                <td class="px-4 py-2"><input type="number" step="0.01" min="0" max="100" name="tax_rate" x-model.number="tax" class="form-control text-right"></td>
                            </tr>
                            <tr><td colspan="7" class="px-4 py-2 text-right">Nilai PPN</td><td class="px-4 py-2 text-right whitespace-nowrap" x-text="'Rp ' + ERP.formatNumber(taxAmount)"></td></tr>
                            <tr class="font-semibold"><td colspan="7" class="px-4 py-3 text-right">Total Tagihan</td><td class="px-4 py-3 text-right whitespace-nowrap" x-text="'Rp ' + ERP.formatNumber(total)"></td></tr>
                        </tfoot>
                    </table>
                </div>
                <p class="border-t border-gray-100 px-5 py-3 text-xs text-gray-500">Jumlah tagih dibatasi barang yang sudah diterima gudang (pencocokan PO – penerimaan – faktur). Ubah harga bila faktur supplier berbeda dari harga PO.</p>
            </x-card>

            <div class="flex justify-end gap-2">
                <a href="{{ route('purchase-orders.show', $order) }}" class="btn-secondary">Batal</a>
                <button type="submit" class="btn-primary">Simpan Tagihan</button>
            </div>
        </form>
    @endif
</x-layouts.app>
