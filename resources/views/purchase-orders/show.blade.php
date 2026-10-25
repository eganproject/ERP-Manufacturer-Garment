@php use App\Enums\PurchaseOrderStatus; @endphp
<x-layouts.app :title="'PO '.$order->number">
    <x-slot:actions>
        @if ($order->isEditable())
            <x-delete-button :action="route('purchase-orders.destroy', $order)" class="btn-secondary !text-red-600" />
            <a href="{{ route('purchase-orders.edit', $order) }}" class="btn-secondary">Ubah</a>
            <x-action-form :action="route('purchase-orders.submit', $order)" confirm="PO akan dikunci dan ditandai sudah dipesan ke supplier." icon="question" button="Ya, pesan" class="btn-primary">Tandai Dipesan</x-action-form>
        @endif
        @if (in_array($order->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Ordered], true))
            <x-action-form :action="route('purchase-orders.cancel', $order)" confirm="PO akan dibatalkan." button="Ya, batalkan">Batalkan</x-action-form>
        @endif
        @if ($order->canReceive())
            <a href="{{ route('purchase-orders.receive', $order) }}" class="btn-success">Terima Barang</a>
        @endif
    </x-slot:actions>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Informasi">
            <dl class="space-y-3 text-sm">
                <div><dt class="text-gray-500">Status</dt><dd><x-status-badge :status="$order->status" /></dd></div>
                <div><dt class="text-gray-500">Supplier</dt><dd class="font-medium">{{ $order->supplier->name }}</dd><dd class="text-gray-500">{{ $order->supplier->phone }}</dd></div>
                <div><dt class="text-gray-500">Tanggal PO</dt><dd>{{ tanggal($order->order_date) }}</dd></div>
                <div><dt class="text-gray-500">Estimasi Datang</dt><dd>{{ tanggal($order->expected_date) }}</dd></div>
                <div><dt class="text-gray-500">Dibuat oleh</dt><dd>{{ $order->creator?->name ?? '-' }}</dd></div>
                @if ($order->notes)<div><dt class="text-gray-500">Catatan</dt><dd>{{ $order->notes }}</dd></div>@endif
            </dl>
        </x-card>

        <x-card title="Item" :padding="false" class="lg:col-span-2">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>Bahan</th><th class="text-right">Qty</th><th class="text-right">Diterima</th><th class="text-right">Harga</th><th class="text-right">Subtotal</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($order->items as $item)
                            <tr>
                                <td>{{ $item->material->display_name }}</td>
                                <td class="text-right">{{ qty($item->quantity) }} {{ $item->material->unit->code }}</td>
                                <td @class(['text-right', 'text-emerald-600 font-medium' => $item->outstandingQuantity() <= 0])>{{ qty($item->received_quantity) }}</td>
                                <td class="text-right">{{ rupiah($item->price) }}</td>
                                <td class="text-right">{{ rupiah($item->subtotal) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr class="bg-gray-50 font-semibold"><td colspan="4" class="px-4 py-3 text-right">Total</td><td class="px-4 py-3 text-right">{{ rupiah($order->total) }}</td></tr></tfoot>
                </table>
            </div>
        </x-card>

        <x-card title="Riwayat Penerimaan Barang" :padding="false" class="lg:col-span-3">
            <table class="table-base">
                <thead><tr><th>No. Penerimaan</th><th>Tanggal</th><th>Surat Jalan</th><th>Item</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($order->receipts as $receipt)
                        <tr>
                            <td class="font-medium">{{ $receipt->number }}</td>
                            <td>{{ tanggal($receipt->receipt_date) }}</td>
                            <td>{{ $receipt->delivery_note ?: '-' }}</td>
                            <td>{!! $receipt->items->map(fn ($i) => e($i->material->name).': <b>'.qty($i->quantity).'</b>')->implode('<br>') !!}</td>
                        </tr>
                    @empty
                        <x-empty-row colspan="4" message="Belum ada penerimaan barang." />
                    @endforelse
                </tbody>
            </table>
        </x-card>
    </div>
</x-layouts.app>
