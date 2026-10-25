{{-- Detail invoice penjualan / tagihan supplier. $doc, $party, $source, $sourceRoute, $sourceLabel, $sourceCan --}}
@php $isInvoice = $doc instanceof \App\Models\Invoice; @endphp
<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <x-card title="Informasi">
        <dl class="space-y-3 text-sm">
            <div><dt class="text-gray-500">Status</dt><dd><x-payable-status :doc="$doc" /></dd></div>
            <div><dt class="text-gray-500">{{ $isInvoice ? 'Pelanggan' : 'Supplier' }}</dt><dd class="font-medium">{{ $party->name }}</dd><dd class="text-gray-500">{{ $party->phone }}</dd></div>
            <div>
                <dt class="text-gray-500">{{ $sourceLabel }}</dt>
                <dd>
                    @can($sourceCan)
                        <a href="{{ route($sourceRoute, $source) }}" class="font-medium text-indigo-600 hover:underline">{{ $source->number }}</a>
                    @else
                        {{ $source->number }}
                    @endcan
                </dd>
            </div>
            @unless ($isInvoice)
                <div><dt class="text-gray-500">No. Faktur Supplier</dt><dd>{{ $doc->supplier_invoice_number ?: '-' }}</dd></div>
            @endunless
            <div><dt class="text-gray-500">Tanggal</dt><dd>{{ tanggal($isInvoice ? $doc->invoice_date : $doc->bill_date) }}</dd></div>
            <div>
                <dt class="text-gray-500">Jatuh Tempo</dt>
                <dd @class(['text-red-600 font-medium' => $doc->isOverdue()])>
                    {{ tanggal($doc->due_date) }}
                    @if ($doc->isOverdue()) · lewat {{ $doc->daysOverdue() }} hari @endif
                </dd>
            </div>
            <div><dt class="text-gray-500">Dibuat oleh</dt><dd>{{ $doc->creator?->name ?? '-' }}</dd></div>
            @if ($isInvoice && auth()->user()->can('costing.view'))
                @php $profit = $doc->grossProfit(); @endphp
                <div>
                    <dt class="text-gray-500">HPP / Laba Kotor</dt>
                    <dd>{{ rupiah($doc->costOfGoods()) }} / <span @class(['font-medium', 'text-emerald-700' => $profit >= 0, 'text-red-600' => $profit < 0])>{{ rupiah($profit) }}</span>
                        @if ($doc->netSales() > 0)<span class="text-gray-500">({{ number_format($profit / $doc->netSales() * 100, 1, ',', '.') }}%)</span>@endif
                    </dd>
                </div>
            @endif
            @if ($doc->notes)<div><dt class="text-gray-500">Catatan</dt><dd>{{ $doc->notes }}</dd></div>@endif
        </dl>
    </x-card>

    <x-card title="Item" :padding="false" class="lg:col-span-2">
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>{{ $isInvoice ? 'Produk' : 'Bahan / Jasa' }}</th><th class="text-right">Qty</th><th class="text-right">Harga</th><th class="text-right">Subtotal</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($doc->items as $item)
                        <tr>
                            @if ($isInvoice)
                                <td>{{ $item->variant->display_name }}<div class="text-xs text-gray-500">{{ $item->variant->sku }}</div></td>
                                <td class="text-right">{{ qty($item->quantity) }}</td>
                            @elseif ($item->material)
                                <td>{{ $item->material->display_name }}</td>
                                <td class="text-right">{{ qty($item->quantity) }} {{ $item->material->unit->code }}</td>
                            @else
                                <td>{{ $item->description }}</td>
                                <td class="text-right">{{ qty($item->quantity) }} pcs</td>
                            @endif
                            <td class="text-right whitespace-nowrap">{{ rupiah($item->price) }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($item->subtotal) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-gray-50">
                    <tr><td colspan="3" class="px-4 py-2 text-right">Subtotal</td><td class="px-4 py-2 text-right whitespace-nowrap">{{ rupiah($doc->subtotal) }}</td></tr>
                    @if ((float) $doc->discount > 0)
                        <tr><td colspan="3" class="px-4 py-2 text-right">Diskon</td><td class="px-4 py-2 text-right whitespace-nowrap">- {{ rupiah($doc->discount) }}</td></tr>
                    @endif
                    @if ((float) $doc->tax_rate > 0)
                        <tr><td colspan="3" class="px-4 py-2 text-right">PPN {{ qty($doc->tax_rate) }}%</td><td class="px-4 py-2 text-right whitespace-nowrap">{{ rupiah($doc->tax_amount) }}</td></tr>
                    @endif
                    <tr class="font-semibold"><td colspan="3" class="px-4 py-2 text-right">Total</td><td class="px-4 py-2 text-right whitespace-nowrap">{{ rupiah($doc->total) }}</td></tr>
                    <tr><td colspan="3" class="px-4 py-2 text-right text-emerald-700">Sudah dibayar</td><td class="px-4 py-2 text-right whitespace-nowrap text-emerald-700">{{ rupiah($doc->paid_amount) }}</td></tr>
                    <tr class="font-semibold"><td colspan="3" class="px-4 py-3 text-right">Sisa Tagihan</td><td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($doc->outstanding()) }}</td></tr>
                </tfoot>
            </table>
        </div>
    </x-card>

    <x-card title="Riwayat Pembayaran" :padding="false" class="lg:col-span-3">
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>No. Bukti</th><th>Tanggal</th><th>Metode</th><th>Referensi</th><th class="text-right">Jumlah</th><th>Status</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($doc->allocations->sortBy('payment.payment_date') as $allocation)
                        @php $payment = $allocation->payment; @endphp
                        <tr @class(['opacity-60 line-through' => $payment->isCancelled()])>
                            <td><a href="{{ route('payments.show', $payment) }}" class="font-medium text-indigo-600 hover:underline">{{ $payment->number }}</a></td>
                            <td>{{ tanggal($payment->payment_date) }}</td>
                            <td>{{ $payment->method->label() }}</td>
                            <td>{{ $payment->reference ?: '-' }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($allocation->amount) }}</td>
                            <td><x-status-badge :status="$payment->status" /></td>
                        </tr>
                    @empty
                        <x-empty-row colspan="6" message="Belum ada pembayaran." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <x-activity-log :activities="$doc->activities" class="lg:col-span-3" />
</div>
