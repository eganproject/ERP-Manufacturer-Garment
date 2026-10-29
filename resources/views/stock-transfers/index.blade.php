<x-layouts.app title="Transfer Antar Gudang">
    @can('stock.transfer')
        <x-slot:actions><a href="{{ route('stock-transfers.create') }}" class="btn-primary">+ Transfer Baru</a></x-slot:actions>
    @endcan

    <x-card :padding="false">
        <div class="border-b border-gray-100 p-4">
            <x-search-filter placeholder="Cari no. transfer...">
                <div class="w-56"><x-form.select name="warehouse_id" :options="$warehouses" :value="request('warehouse_id')" placeholder="Semua gudang" /></div>
            </x-search-filter>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>No.</th><th>Tanggal</th><th>Dari</th><th>Ke</th><th class="text-right">Barang</th><th>Status</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($transfers as $transfer)
                        <tr>
                            <td><a href="{{ route('stock-transfers.show', $transfer) }}" class="font-medium text-indigo-600 hover:underline">{{ $transfer->number }}</a></td>
                            <td class="whitespace-nowrap">{{ tanggal($transfer->date) }}</td>
                            <td>{{ $transfer->fromWarehouse->name }}</td>
                            <td>{{ $transfer->toWarehouse->name }}</td>
                            <td class="text-right">{{ $transfer->items->count() }}</td>
                            <td><x-status-badge :status="$transfer->status" /></td>
                        </tr>
                    @empty
                        <x-empty-row colspan="6" message="Belum ada transfer antar gudang." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-4 py-3 empty:hidden">{{ $transfers->links() }}</div>
    </x-card>
</x-layouts.app>
