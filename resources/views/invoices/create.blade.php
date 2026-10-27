<x-layouts.app title="Buat Invoice Penjualan">
    <x-guide title="Cara membuat invoice" :steps="['Pilih Sales Order yang barangnya **sudah dikirim** (sudah ada surat jalan). Invoice hanya bisa menagih barang yang terkirim.', 'Periksa tanggal, jatuh tempo dan PPN, lalu isi jumlah di kolom **Tagih Sekarang** (boleh sebagian).', 'Setelah disimpan, piutang pelanggan tercatat dan invoice bisa dicetak. Pembayaran dicatat lewat **Catat Penerimaan**.']"></x-guide>
    <x-card title="1. Pilih Sales Order" class="mb-6" tip="Hanya SO yang punya barang terkirim tetapi belum ditagih yang muncul di sini.">
        <form method="GET" action="{{ route('invoices.create') }}" class="flex flex-wrap items-end gap-2">
            <div class="w-full sm:w-[28rem]">
                <x-form.select name="sales_order_id" :value="$order?->id" placeholder="Pilih SO yang sudah dikirim..." data-select
                    :options="$billableOrders->mapWithKeys(fn ($o) => [$o->id => $o->number.' · '.$o->customer->name])" />
            </div>
            <button type="submit" class="btn-secondary">Tampilkan</button>
        </form>
        @if ($billableOrders->isEmpty())
            <p class="mt-3 text-sm text-gray-500">Belum ada SO dengan barang terkirim yang belum ditagih. Invoice dibuat setelah surat jalan.</p>
        @endif
    </x-card>

    @if ($order)
        <form method="POST" action="{{ route('invoices.store') }}" class="space-y-6"
              data-confirm="Invoice akan dibuat untuk jumlah barang yang diisi." data-confirm-icon="question" data-confirm-button="Ya, buat invoice">
            @csrf
            <input type="hidden" name="sales_order_id" value="{{ $order->id }}">

            <x-card title="2. Informasi Invoice">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <div>
                        <span class="form-label">Pelanggan</span>
                        <p class="py-2 text-sm font-medium">{{ $order->customer->name }}</p>
                        <p class="text-xs text-gray-500">Termin {{ $order->customer->payment_terms }} hari</p>
                    </div>
                    <x-form.input name="invoice_date" label="Tanggal Invoice" tip="Tanggal penagihan. Penjualan & piutang diakui pada tanggal ini di laporan dan jurnal." :value="now()" required data-date />
                    <x-form.input name="due_date" label="Jatuh Tempo" :value="now()->addDays($order->customer->payment_terms)" data-date help="Default: tanggal invoice + termin pelanggan." tip="Batas waktu pelanggan membayar. Invoice yang lewat tanggal ini masuk kolom terlambat di laporan umur piutang." />
                    <x-form.input name="tax_rate" type="number" step="0.01" min="0" max="100" label="PPN (%)" :value="$defaultTaxRate" tip="Tarif PPN yang ditagihkan ke pelanggan (mis. 11). Isi 0 bila perusahaan bukan PKP atau transaksi bebas PPN. Nilainya masuk PPN keluaran di Laporan PPN." />
                    <x-form.textarea name="notes" label="Catatan" rows="2" class="md:col-span-4" />
                </div>
            </x-card>

            <x-card title="3. Barang yang Ditagih" :padding="false" x-data>
                <x-slot:header>
                    <button type="button" class="btn-secondary btn-sm" @click="document.querySelectorAll('[data-uninvoiced]').forEach(el => el.value = el.dataset.uninvoiced)">Isi semua barang terkirim</button>
                </x-slot:header>
                <div class="overflow-x-auto">
                    <table class="table-base">
                        <thead><tr><th>Produk</th><th class="text-right">Dipesan</th><th class="text-right">Terkirim</th><th class="text-right">Sudah Ditagih</th><th class="text-right">Bisa Ditagih <x-tip title="Bisa ditagih" text="Barang terkirim dikurangi yang sudah ditagih dan yang diretur. Ini batas maksimal jumlah yang bisa ditagih sekarang." /></th><th class="text-right">Harga</th><th class="w-40">Tagih Sekarang <x-tip title="Tagih sekarang" text="Jumlah pcs yang dimasukkan ke invoice ini. Sisanya bisa ditagih di invoice berikutnya." /></th></tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($order->items as $item)
                                <tr>
                                    <td>{{ $item->variant->display_name }}<div class="text-xs text-gray-500">{{ $item->variant->sku }}</div></td>
                                    <td class="text-right">{{ qty($item->quantity) }}</td>
                                    <td class="text-right">{{ qty($item->delivered_quantity) }}</td>
                                    <td class="text-right">{{ qty($item->invoiced_quantity) }}</td>
                                    <td class="text-right font-medium">{{ qty($item->uninvoicedQuantity()) }}</td>
                                    <td class="text-right whitespace-nowrap">{{ rupiah($item->price) }}</td>
                                    <td>
                                        @if ($item->uninvoicedQuantity() > 0)
                                            <input type="number" step="any" min="0" max="{{ $item->uninvoicedQuantity() }}" name="items[{{ $item->id }}]"
                                                   value="{{ old('items.'.$item->id, $item->uninvoicedQuantity()) }}" data-uninvoiced="{{ $item->uninvoicedQuantity() }}" class="form-control">
                                        @else
                                            <span class="text-xs text-gray-400">-</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if ((float) $order->discount > 0)
                    <p class="border-t border-gray-100 px-5 py-3 text-xs text-gray-500">Diskon SO {{ rupiah($order->discount) }} dibagi proporsional ke setiap invoice sesuai nilai barang yang ditagih.</p>
                @endif
            </x-card>

            <div class="flex justify-end gap-2">
                <a href="{{ route('sales-orders.show', $order) }}" class="btn-secondary">Batal</a>
                <button type="submit" class="btn-primary">Simpan Invoice</button>
            </div>
        </form>
    @endif
</x-layouts.app>
