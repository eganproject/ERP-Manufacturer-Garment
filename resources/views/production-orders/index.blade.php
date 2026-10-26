<x-layouts.app title="SPK Produksi">
    @can('production.manage')
    <x-slot:actions><a href="{{ route('production-orders.create') }}" class="btn-primary">+ Buat SPK</a></x-slot:actions>
    @endcan

    <x-card :padding="false">
        <div class="border-b border-gray-200 p-4">
            <x-search-filter placeholder="Cari no. SPK / produk...">
                <div class="w-52">
                    <x-form.select name="status" :options="collect(\App\Enums\ProductionOrderStatus::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])" :value="request('status')" placeholder="Semua status" />
                </div>
            </x-search-filter>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>No. SPK</th><th>Produk</th><th>SO</th><th class="text-right">Qty</th><th>Mulai</th><th>Target</th><th class="w-36">Progres</th><th>Status</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($orders as $order)
                        <tr>
                            <td><a href="{{ route('production-orders.show', $order) }}" class="font-medium text-indigo-600 hover:underline">{{ $order->number }}</a></td>
                            <td>{{ $order->product->name }}</td>
                            <td>{{ $order->salesOrder?->number ?? '-' }}</td>
                            <td class="text-right">{{ qty($order->quantity) }}</td>
                            <td>{{ tanggal($order->start_date) }}</td>
                            <td @class(['text-red-600 font-medium' => $order->due_date?->isPast() && $order->status === \App\Enums\ProductionOrderStatus::InProgress])>{{ tanggal($order->due_date) }}</td>
                            <td>
                                <div class="h-2 rounded-full bg-gray-200"><div class="h-2 rounded-full bg-indigo-500" style="width: {{ $order->progressPercent() }}%"></div></div>
                            </td>
                            <td><x-status-badge :status="$order->status" /></td>
                        </tr>
                    @empty
                        <x-empty-row colspan="8" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-4 py-3 empty:hidden">{{ $orders->links() }}</div>
    </x-card>
</x-layouts.app>
