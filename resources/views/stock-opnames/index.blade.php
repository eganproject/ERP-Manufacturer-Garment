<x-layouts.app title="Stock Opname">
    @can('stock.adjust')
        <x-slot:actions><a href="{{ route('stock-opnames.create', array_filter(['warehouse_id' => request('warehouse_id')])) }}" class="btn-primary">+ Stock Opname Baru</a></x-slot:actions>
    @endcan

    <x-card :padding="false">
        <div class="border-b border-gray-200 p-4">
            <x-search-filter placeholder="Cari no. opname...">
                <div class="w-56"><x-form.select name="warehouse_id" :options="$warehouses" :value="request('warehouse_id')" placeholder="Semua gudang" /></div>
                <div class="w-48"><x-form.select name="status" :options="\App\Enums\StockOpnameStatus::options()" :value="request('status')" placeholder="Semua status" /></div>
            </x-search-filter>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>No.</th><th>Tanggal</th><th>Gudang</th><th>Jenis</th><th class="text-right">Barang</th>@can('costing.view')<th class="text-right">Nilai Selisih</th>@endcan<th>Status</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($opnames as $opname)
                        <tr>
                            <td><a href="{{ route('stock-opnames.show', $opname) }}" class="font-medium text-indigo-600 hover:underline">{{ $opname->number }}</a></td>
                            <td class="whitespace-nowrap">{{ tanggal($opname->date) }}</td>
                            <td>{{ $opname->warehouse->name }}</td>
                            <td>{{ $opname->scopeLabel() }}</td>
                            <td class="text-right">{{ $opname->items_count }}</td>
                            @can('costing.view')
                                <td @class(['text-right whitespace-nowrap', 'text-red-600' => (float) $opname->total_value < 0])>{{ $opname->isDraft() ? '-' : rupiah($opname->total_value) }}</td>
                            @endcan
                            <td><x-status-badge :status="$opname->status" /></td>
                        </tr>
                    @empty
                        <x-empty-row colspan="7" message="Belum ada stock opname." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-4 py-3 empty:hidden">{{ $opnames->links() }}</div>
    </x-card>
</x-layouts.app>
