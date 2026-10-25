@php use App\Enums\SalesOrderStatus; @endphp
<x-layouts.app :title="'SO '.$order->number">
    <x-slot:actions>
        @if ($order->isEditable())
            <x-delete-button :action="route('sales-orders.destroy', $order)" class="btn-secondary !text-red-600" />
            <a href="{{ route('sales-orders.edit', $order) }}" class="btn-secondary">Ubah</a>
            <x-action-form :action="route('sales-orders.confirm', $order)" confirm="SO akan dikunci dan siap diproduksi/dikirim." icon="question" button="Ya, konfirmasi" class="btn-primary">Konfirmasi</x-action-form>
        @endif
        @if (in_array($order->status, [SalesOrderStatus::Draft, SalesOrderStatus::Confirmed], true))
            <x-action-form :action="route('sales-orders.cancel', $order)" confirm="SO akan dibatalkan." button="Ya, batalkan">Batalkan</x-action-form>
        @endif
        @if ($order->status === SalesOrderStatus::Confirmed)
            <a href="{{ route('production-orders.create', ['sales_order_id' => $order->id]) }}" class="btn-secondary">Buat SPK</a>
            <x-action-form :action="route('sales-orders.deliver', $order)" confirm="Stok barang jadi akan dikurangi sesuai SO." icon="question" button="Ya, kirim" class="btn-success">Kirim Barang</x-action-form>
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
                    <thead><tr><th>Produk</th><th class="text-right">Qty</th><th class="text-right">Stok</th><th class="text-right">Harga</th><th class="text-right">Subtotal</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($order->items as $item)
                            <tr>
                                <td>{{ $item->variant->display_name }}<div class="text-xs text-gray-500">{{ $item->variant->sku }}</div></td>
                                <td class="text-right">{{ qty($item->quantity) }}</td>
                                <td @class(['text-right', 'text-red-600 font-medium' => $order->status === SalesOrderStatus::Confirmed && $item->variant->stock < $item->quantity])>{{ qty($item->variant->stock) }}</td>
                                <td class="text-right">{{ rupiah($item->price) }}</td>
                                <td class="text-right">{{ rupiah($item->subtotal) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr><td colspan="4" class="px-4 py-2 text-right">Subtotal</td><td class="px-4 py-2 text-right">{{ rupiah($order->subtotal) }}</td></tr>
                        <tr><td colspan="4" class="px-4 py-2 text-right">Diskon</td><td class="px-4 py-2 text-right">- {{ rupiah($order->discount) }}</td></tr>
                        <tr class="font-semibold"><td colspan="4" class="px-4 py-3 text-right">Total</td><td class="px-4 py-3 text-right">{{ rupiah($order->total) }}</td></tr>
                    </tfoot>
                </table>
            </div>
        </x-card>

        <x-card title="SPK Produksi Terkait" :padding="false" class="lg:col-span-3">
            <table class="table-base">
                <thead><tr><th>No. SPK</th><th>Produk</th><th class="text-right">Qty</th><th>Status</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($order->productionOrders as $po)
                        <tr>
                            <td><a href="{{ route('production-orders.show', $po) }}" class="font-medium text-indigo-600 hover:underline">{{ $po->number }}</a></td>
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
    </div>
</x-layouts.app>
