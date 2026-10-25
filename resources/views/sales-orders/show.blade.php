@php use App\Enums\SalesOrderStatus; @endphp
<x-layouts.app :title="'SO '.$order->number">
    <x-slot:actions>
        <a href="{{ route('sales-orders.pdf', $order) }}" target="_blank" class="btn-secondary">Cetak SO</a>
        @can('sales.manage')
            @if ($order->isEditable())
                <x-delete-button :action="route('sales-orders.destroy', $order)" class="btn-secondary !text-red-600" />
                <a href="{{ route('sales-orders.edit', $order) }}" class="btn-secondary">Ubah</a>
                <x-action-form :action="route('sales-orders.confirm', $order)" confirm="SO akan dikunci dan siap diproduksi/dikirim." icon="question" button="Ya, konfirmasi" class="btn-primary">Konfirmasi</x-action-form>
            @endif
            @if ($order->canCancel())
                <x-action-form :action="route('sales-orders.cancel', $order)" confirm="SO akan dibatalkan." button="Ya, batalkan">Batalkan</x-action-form>
            @endif
        @endcan
        @if ($order->canDeliver())
            @can('mrp.view')
                <a href="{{ route('mrp.index', ['sales_order_ids' => [$order->id]]) }}" class="btn-secondary">Cek Kebutuhan Bahan</a>
            @endcan
            @can('production.manage')
                <a href="{{ route('production-orders.create', ['sales_order_id' => $order->id]) }}" class="btn-secondary">Buat SPK</a>
            @endcan
            @can('sales.deliver')
                <a href="{{ route('sales-orders.deliver', $order) }}" class="btn-success">Kirim Barang</a>
            @endcan
        @endif
        @if ($order->canReturn())
            @can('sales-returns.manage')
                <a href="{{ route('sales-returns.create', ['order_id' => $order->id]) }}" class="btn-secondary">Retur Barang</a>
            @endcan
        @endif
        @if ($order->canInvoice())
            @can('receivables.manage')
                <a href="{{ route('invoices.create', ['sales_order_id' => $order->id]) }}" class="btn-primary">Buat Invoice</a>
            @endcan
        @endif
    </x-slot:actions>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Informasi">
            <dl class="space-y-3 text-sm">
                <div><dt class="text-gray-500">Status</dt><dd><x-status-badge :status="$order->status" /></dd></div>
                <div><dt class="text-gray-500">Pelanggan</dt><dd class="font-medium">{{ $order->customer->name }}</dd><dd class="text-gray-500">{{ $order->customer->phone }}</dd></div>
                <div><dt class="text-gray-500">Tanggal Order</dt><dd>{{ tanggal($order->order_date) }}</dd></div>
                <div><dt class="text-gray-500">Target Kirim</dt><dd>{{ tanggal($order->delivery_date) }}</dd></div>
                <div><dt class="text-gray-500">Dibuat oleh</dt><dd>{{ $order->creator?->name ?? '-' }}</dd></div>
                @if ($order->notes)<div><dt class="text-gray-500">Catatan</dt><dd>{{ $order->notes }}</dd></div>@endif
            </dl>
        </x-card>

        <x-card title="Item" :padding="false" class="lg:col-span-2">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>Produk</th><th class="text-right">Qty</th><th class="text-right">Terkirim</th><th class="text-right">Ditagih</th><th class="text-right">Retur</th><th class="text-right">Stok</th><th class="text-right">Harga</th><th class="text-right">Subtotal</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($order->items as $item)
                            <tr>
                                <td>{{ $item->variant->display_name }}<div class="text-xs text-gray-500">{{ $item->variant->sku }}</div></td>
                                <td class="text-right">{{ qty($item->quantity) }}</td>
                                <td @class(['text-right', 'text-emerald-600 font-medium' => $item->outstandingQuantity() <= 0])>{{ qty($item->delivered_quantity) }}</td>
                                <td @class(['text-right', 'text-amber-600' => $item->uninvoicedQuantity() > 0])>{{ qty($item->invoiced_quantity) }}</td>
                                <td @class(['text-right', 'text-red-600' => (float) $item->returned_quantity > 0])>{{ qty($item->returned_quantity) }}</td>
                                <td @class(['text-right', 'text-red-600 font-medium' => $order->canDeliver() && $item->variant->stock < $item->outstandingQuantity()])>{{ qty($item->variant->stock) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($item->price) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($item->subtotal) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr><td colspan="7" class="px-4 py-2 text-right">Subtotal</td><td class="px-4 py-2 text-right">{{ rupiah($order->subtotal) }}</td></tr>
                        <tr><td colspan="7" class="px-4 py-2 text-right">Diskon</td><td class="px-4 py-2 text-right">- {{ rupiah($order->discount) }}</td></tr>
                        <tr class="font-semibold"><td colspan="7" class="px-4 py-3 text-right">Total</td><td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($order->total) }}</td></tr>
                    </tfoot>
                </table>
            </div>
        </x-card>

        <x-card title="Surat Jalan / Pengiriman" :padding="false" class="lg:col-span-3">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>No. Surat Jalan</th><th>Tanggal</th><th>Kendaraan / Sopir</th><th>Item</th><th></th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($order->deliveries as $delivery)
                            <tr>
                                <td class="font-medium">{{ $delivery->number }}</td>
                                <td>{{ tanggal($delivery->delivery_date) }}</td>
                                <td>{{ $delivery->vehicle_number ?: '-' }}<div class="text-xs text-gray-500">{{ $delivery->driver_name }}</div></td>
                                <td>{!! $delivery->items->map(fn ($i) => e($i->variant->display_name).': <b>'.qty($i->quantity).'</b>')->implode('<br>') !!}</td>
                                <td class="text-right"><a href="{{ route('deliveries.pdf', $delivery) }}" target="_blank" class="text-sm font-medium text-indigo-600 hover:underline">Cetak</a></td>
                            </tr>
                        @empty
                            <x-empty-row colspan="5" message="Belum ada pengiriman." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        @can('receivables.view')
        <x-card title="Invoice" :padding="false" class="lg:col-span-3">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>No. Invoice</th><th>Tanggal</th><th>Jatuh Tempo</th><th class="text-right">Total</th><th class="text-right">Sisa</th><th>Status</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($order->invoices as $invoice)
                            <tr>
                                <td><a href="{{ route('invoices.show', $invoice) }}" class="font-medium text-indigo-600 hover:underline">{{ $invoice->number }}</a></td>
                                <td>{{ tanggal($invoice->invoice_date) }}</td>
                                <td>{{ tanggal($invoice->due_date) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($invoice->total) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($invoice->outstanding()) }}</td>
                                <td><x-payable-status :doc="$invoice" /></td>
                            </tr>
                        @empty
                            <x-empty-row colspan="6" message="Belum ada invoice untuk SO ini." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
        @endcan

        @if ($order->returns->isNotEmpty())
            @include('returns.partials.order-returns', ['kind' => \App\Support\ReturnKind::sales(), 'returns' => $order->returns])
        @endif

        <x-card title="SPK Produksi Terkait" :padding="false" class="lg:col-span-2">
            <table class="table-base">
                <thead><tr><th>No. SPK</th><th>Produk</th><th class="text-right">Qty</th><th>Status</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($order->productionOrders as $po)
                        <tr>
                            <td>
                                @can('production.view')
                                    <a href="{{ route('production-orders.show', $po) }}" class="font-medium text-indigo-600 hover:underline">{{ $po->number }}</a>
                                @else
                                    {{ $po->number }}
                                @endcan
                            </td>
                            <td>{{ $po->product->name }}</td>
                            <td class="text-right">{{ qty($po->quantity) }}</td>
                            <td><x-status-badge :status="$po->status" /></td>
                        </tr>
                    @empty
                        <x-empty-row colspan="4" message="Belum ada SPK untuk SO ini." />
                    @endforelse
                </tbody>
            </table>
        </x-card>

        <x-journal-entries :sources="$order->deliveries->all()" />
        <x-activity-log :activities="$order->activities" />
    </div>
</x-layouts.app>
