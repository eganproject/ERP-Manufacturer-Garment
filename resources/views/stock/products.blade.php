<x-layouts.app title="Stok Barang Jadi">
    <x-slot:actions>
        <a href="{{ route('defects.index') }}" class="btn-secondary">Barang Cacat</a>
        @can('stock.adjust')
            <a href="{{ route('stock.adjustment') }}" class="btn-secondary">Penyesuaian Stok</a>
        @endcan
    </x-slot:actions>

    <x-card :padding="false">
        <div class="border-b border-gray-200 p-4"><x-search-filter placeholder="Cari SKU / nama produk..." /></div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>SKU</th><th>Produk</th><th>Ukuran</th><th>Warna</th><th class="text-right">Stok Jual</th><th class="text-right">Stok Cacat</th><th></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($items as $v)
                        <tr>
                            <td class="font-medium">{{ $v->sku }}</td>
                            <td>{{ $v->product->name }}</td>
                            <td>{{ $v->size }}</td>
                            <td>{{ $v->color ?: '-' }}</td>
                            <td class="text-right font-semibold">{{ qty($v->stock) }} {{ $v->product->unit->code }}</td>
                            <td class="text-right">@if ((float) $v->defect_stock > 0)<a href="{{ route('defects.index') }}" class="text-red-600 hover:underline">{{ qty($v->defect_stock) }}</a>@else<span class="text-gray-400">-</span>@endif</td>
                            <td class="text-right"><a href="{{ route('stock.products.card', $v) }}" class="text-sm text-indigo-600 hover:underline">Kartu Stok</a></td>
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
