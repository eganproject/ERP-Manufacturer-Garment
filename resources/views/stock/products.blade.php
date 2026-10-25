<x-layouts.app title="Stok Barang Jadi">
    <x-slot:actions><a href="{{ route('stock.adjustment') }}" class="btn-secondary">Penyesuaian Stok</a></x-slot:actions>

    <x-card :padding="false">
        <div class="border-b border-gray-200 p-4"><x-search-filter placeholder="Cari SKU / nama produk..." /></div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>SKU</th><th>Produk</th><th>Ukuran</th><th>Warna</th><th class="text-right">Stok</th><th></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($items as $v)
                        <tr>
                            <td class="font-medium">{{ $v->sku }}</td>
                            <td>{{ $v->product->name }}</td>
                            <td>{{ $v->size }}</td>
                            <td>{{ $v->color ?: '-' }}</td>
                            <td class="text-right font-semibold">{{ qty($v->stock) }} {{ $v->product->unit->code }}</td>
                            <td class="text-right"><a href="{{ route('stock.products.card', $v) }}" class="text-sm text-indigo-600 hover:underline">Kartu Stok</a></td>
                        </tr>
                    @empty
                        <x-empty-row colspan="6" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4">{{ $items->links() }}</div>
    </x-card>
</x-layouts.app>
