@php
    $lines = $order->receipts->map(fn ($r) => ['id' => $r->id, 'amount' => (float) $r->amount, 'on' => true])->values();
@endphp
<x-layouts.app :title="'Tagihan Jasa Maklon - '.$order->number">
    <form method="POST" action="{{ route('bills.store') }}" class="space-y-6"
          x-data="{ lines: @js($lines), discount: {{ (float) old('discount', 0) }}, tax: {{ (float) old('tax_rate', 0) }},
                    get subtotal() { return this.lines.reduce((s, l) => s + (l.on ? l.amount : 0), 0) },
                    get taxAmount() { return Math.round(Math.max(this.subtotal - this.discount, 0) * this.tax / 100) },
                    get total() { return Math.max(this.subtotal - this.discount, 0) + this.taxAmount } }"
          data-confirm="Tagihan jasa maklon akan dicatat sebagai hutang ke vendor." data-confirm-icon="question" data-confirm-button="Ya, simpan">
        @csrf
        <input type="hidden" name="subcontract_order_id" value="{{ $order->id }}">

        <x-card title="Informasi Tagihan">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <div>
                    <span class="form-label">Vendor Maklon</span>
                    <p class="py-2 text-sm font-medium">{{ $order->supplier->name }}</p>
                    <p class="text-xs text-gray-500">{{ $order->number }} · SPK {{ $order->productionOrder->number }} · termin {{ $order->supplier->payment_terms }} hari</p>
                </div>
                <x-form.input name="supplier_invoice_number" label="No. Nota / Faktur Vendor" />
                <x-form.input name="bill_date" label="Tanggal Nota" :value="now()" required data-date />
                <x-form.input name="due_date" label="Jatuh Tempo" :value="now()->addDays($order->supplier->payment_terms)" data-date />
                <x-form.input name="tax_invoice_number" label="No. Faktur Pajak" placeholder="Bila tagihan ber-PPN" help="Dipakai di laporan PPN masukan." />
                    <x-form.input name="tax_invoice_date" label="Tgl Faktur Pajak" data-date help="Kosong = tanggal tagihan." />
                    <x-form.textarea name="notes" label="Catatan" rows="2" class="md:col-span-2" />
            </div>
        </x-card>

        <x-card title="Penerimaan Hasil yang Ditagih" :padding="false">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th class="w-10"></th><th>No. Terima</th><th>Tanggal</th><th>SJ Vendor</th><th class="text-right">Hasil Bagus</th><th class="text-right">Tarif</th><th class="text-right">Ongkos Jasa</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($order->receipts as $idx => $receipt)
                            <tr>
                                <td><input type="checkbox" name="receipts[]" value="{{ $receipt->id }}" x-model="lines[{{ $idx }}].on" class="rounded border-gray-300 text-indigo-600"></td>
                                <td class="font-medium">{{ $receipt->number }}</td>
                                <td>{{ tanggal($receipt->date) }}</td>
                                <td>{{ $receipt->vendor_reference ?: '-' }}</td>
                                <td class="text-right">{{ qty($receipt->good_quantity) }} pcs</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($receipt->rate) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($receipt->amount) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr><td colspan="6" class="px-4 py-2 text-right">Subtotal</td><td class="px-4 py-2 text-right whitespace-nowrap" x-text="'Rp ' + ERP.formatNumber(subtotal)"></td></tr>
                        <tr><td colspan="6" class="px-4 py-2 text-right">Diskon / Potongan (Rp)</td><td class="px-4 py-2"><input type="number" step="any" min="0" name="discount" x-model.number="discount" class="form-control text-right"></td></tr>
                        <tr><td colspan="6" class="px-4 py-2 text-right">PPN (%)</td><td class="px-4 py-2"><input type="number" step="0.01" min="0" max="100" name="tax_rate" x-model.number="tax" class="form-control text-right"></td></tr>
                        <tr><td colspan="6" class="px-4 py-2 text-right">Nilai PPN</td><td class="px-4 py-2 text-right whitespace-nowrap" x-text="'Rp ' + ERP.formatNumber(taxAmount)"></td></tr>
                        <tr class="font-semibold"><td colspan="6" class="px-4 py-3 text-right">Total Tagihan</td><td class="px-4 py-3 text-right whitespace-nowrap" x-text="'Rp ' + ERP.formatNumber(total)"></td></tr>
                    </tfoot>
                </table>
            </div>
            <p class="border-t border-gray-100 px-5 py-3 text-xs text-gray-500">Nilai tagihan mengikuti ongkos jasa yang sudah dibebankan ke HPP SPK (hasil bagus × tarif order maklon). Potongan untuk reject / denda bisa dimasukkan sebagai diskon.</p>
        </x-card>

        <div class="flex justify-end gap-2">
            <a href="{{ route('subcontract-orders.show', $order) }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary">Simpan Tagihan</button>
        </div>
    </form>
</x-layouts.app>
