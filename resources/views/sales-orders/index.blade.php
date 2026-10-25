<x-layouts.app title="Sales Order (Penjualan)">
    @can('sales.manage')
    <x-slot:actions><a href="{{ route('sales-orders.create') }}" class="btn-primary">+ Buat SO</a></x-slot:actions>
    @endcan

    <x-card :padding="false">
        <div class="border-b border-gray-200 p-4">
            <x-search-filter placeholder="Cari no. SO / pelanggan...">
                <div class="w-52">
                    <x-form.select name="status" :options="['open' => 'Belum selesai dikirim'] + collect(\App\Enums\SalesOrderStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])->all()" :value="request('status')" placeholder="Semua status" />
                </div>
            </x-search-filter>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>No. SO</th><th>Pelanggan</th><th>Tanggal</th><th>Tgl Kirim</th><th class="text-right">Total</th><th>Status</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($orders as $order)
                        <tr>
                            <td><a href="{{ route('sales-orders.show', $order) }}" class="font-medium text-indigo-600 hover:underline">{{ $order->number }}</a></td>
                            <td>{{ $order->customer->name }}</td>
                            <td>{{ tanggal($order->order_date) }}</td>
                            <td>{{ tanggal($order->delivery_date) }}</td>
                            <td class="text-right">{{ rupiah($order->total) }}</td>
                            <td><x-status-badge :status="$order->status" /></td>
                        </tr>
                    @empty
                        <x-empty-row colspan="6" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4">{{ $orders->links() }}</div>
    </x-card>
</x-layouts.app>
