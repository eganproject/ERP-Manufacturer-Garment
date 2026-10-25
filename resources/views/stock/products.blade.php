<x-layouts.app title="Stok Barang Jadi">
    <x-slot:actions>
        <a href="{{ route('defects.index') }}" class="btn-secondary">Barang Cacat</a>
        @can('stock.adjust')
            <a href="{{ route('stock.adjustment') }}" class="btn-secondary">Penyesuaian Stok</a>
        @endcan
    </x-slot:actions>

    <x-card :padding="false">
        <div class="border-b border-gray-200 p-4">
            <x-search-filter placeholder="Cari SKU / nama produk...">
                @include('stock.partials.warehouse-filter')
            </x-search-filter>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>SKU</th><th>Produk</th><th>Ukuran</th><th>Warna</th><th class="text-right">Stok Jual{{ $warehouse ? ' di '.$warehouse->code : '' }}</th><th class="text-right">Stok Cacat</th><th></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($items as $v)
                        @php
                            $onHand = $warehouse ? $v->stockIn($warehouse) : (float) $v->stock;
                            $defectHand = $warehouse ? $v->stockIn($warehouse, true) : (float) $v->defect_stock;
                        @endphp
                        <tr>
                            <td class="font-medium">{{ $v->sku }}</td>
                            <td>{{ $v->product->name }}</td>
                            <td>{{ $v->size }}</td>
                            <td>{{ $v->color ?: '-' }}</td>
                            <td class="text-right font-semibold">
                                {{ qty($onHand) }} {{ $v->product->unit->code }}
                                @unless ($warehouse)@include('stock.partials.warehouse-breakdown', ['item' => $v])@endunless
                            </td>
                            <td class="text-right">@if ($defectHand > 0)<a href="{{ route('defects.index') }}" class="text-red-600 hover:underline">{{ qty($defectHand) }}</a>@else<span class="text-gray-400">-</span>@endif</td>
                            <td class="text-right"><a href="{{ route('stock.products.card', array_filter([$v, 'warehouse_id' => $warehouse?->id])) }}" class="text-sm text-indigo-600 hover:underline">Kartu Stok</a></td>
                        </tr>
                    @empty
                        <x-empty-row colspan="7" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4">{{ $items->links() }}</div>
    </x-card>
</x-layouts.app>
