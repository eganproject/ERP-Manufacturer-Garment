<x-layouts.app :title="'Gudang '.$warehouse->name">
    <x-slot:actions>
        @can('stock.transfer')<a href="{{ route('stock-transfers.create', ['from_warehouse_id' => $warehouse->id]) }}" class="btn-secondary">Transfer dari Gudang Ini</a>@endcan
        @can('stock.adjust')<a href="{{ route('stock-opnames.create', ['warehouse_id' => $warehouse->id]) }}" class="btn-secondary">Stock Opname</a>@endcan
    </x-slot:actions>

    <div class="mb-4 flex flex-wrap items-center gap-2">
        @foreach (['materials' => 'Bahan Baku', 'products' => 'Barang Jadi'] as $key => $label)
            <a href="{{ route('warehouses.show', [$warehouse, 'type' => $key]) }}"
               @class(['rounded-full border px-3 py-1 text-sm', 'border-indigo-500 bg-indigo-50 font-medium text-indigo-700' => $type === $key, 'border-gray-200 text-gray-600 hover:border-indigo-300' => $type !== $key])>{{ $label }}</a>
        @endforeach
        @can('costing.view')<span class="ml-auto text-sm text-gray-500">Nilai stok gudang: <strong class="text-gray-900">{{ rupiah($value) }}</strong></span>@endcan
    </div>

    <x-card :padding="false">
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>{{ $type === 'materials' ? 'Bahan' : 'Produk' }}</th><th class="text-right">Stok di Gudang</th>@if ($type === 'products')<th class="text-right">Stok Cacat</th>@endif<th class="text-right">Stok Semua Gudang</th><th></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($rows as $row)
                        @php $item = $row->stockable; @endphp
                        <tr>
                            <td>{{ $type === 'materials' ? $item->display_name : $item->display_name }}<div class="text-xs text-gray-500">{{ $type === 'materials' ? $item->code : $item->sku }}</div></td>
                            <td class="text-right font-semibold">{{ qty($row->quantity) }} {{ $type === 'materials' ? $item->unit?->code : '' }}</td>
                            @if ($type === 'products')<td @class(['text-right', 'text-red-600' => (float) $row->defect_quantity > 0])>{{ qty($row->defect_quantity) }}</td>@endif
                            <td class="text-right text-gray-500">{{ qty($item->stock) }}</td>
                            <td class="text-right"><a href="{{ route($type === 'materials' ? 'stock.materials.card' : 'stock.products.card', [$item, 'warehouse_id' => $warehouse->id]) }}" class="text-sm text-indigo-600 hover:underline">Kartu Stok</a></td>
                        </tr>
                    @empty
                        <x-empty-row compact :colspan="$type === 'products' ? 5 : 4" message="Belum ada stok di gudang ini." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</x-layouts.app>
