<x-layouts.app title="Stok Bahan Baku">
    <x-slot:actions>
        @can('stock.adjust')
            <a href="{{ route('stock.adjustment') }}" class="btn-secondary">Penyesuaian Stok</a>
        @endcan
    </x-slot:actions>

    <x-card :padding="false">
        <div class="border-b border-gray-200 p-4">
            <x-search-filter placeholder="Cari kode / nama bahan...">
                @include('stock.partials.warehouse-filter')
                <label class="inline-flex items-center gap-2 py-2 text-sm text-gray-700">
                    <input type="checkbox" name="low_stock" value="1" class="rounded border-gray-300 text-indigo-600" @checked(request('low_stock'))> Hanya stok menipis
                </label>
            </x-search-filter>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>Kode</th><th>Bahan</th><th>Kategori</th><th class="text-right">Stok{{ $warehouse ? ' di '.$warehouse->code : '' }}</th><th class="text-right">Minimum</th><th class="text-right">Nilai Stok</th><th></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($items as $m)
                        @php $onHand = $warehouse ? $m->stockIn($warehouse) : (float) $m->stock; @endphp
                        <tr>
                            <td class="font-medium">{{ $m->code }}</td>
                            <td>{{ $m->name }}{{ $m->color ? ' ('.$m->color.')' : '' }}</td>
                            <td>{{ $m->category?->name ?? '-' }}</td>
                            <td @class(['text-right font-semibold', 'text-red-600' => $m->isLowStock()])>
                                {{ qty($onHand) }} {{ $m->unit->code }}
                                @unless ($warehouse)@include('stock.partials.warehouse-breakdown', ['item' => $m])@endunless
                            </td>
                            <td class="text-right">{{ qty($m->min_stock) }}</td>
                            <td class="text-right">{{ rupiah($onHand * (float) $m->price) }}</td>
                            <td class="text-right"><a href="{{ route('stock.materials.card', array_filter([$m, 'warehouse_id' => $warehouse?->id])) }}" class="text-sm text-indigo-600 hover:underline">Kartu Stok</a></td>
                        </tr>
                    @empty
                        <x-empty-row colspan="7" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-4 py-3 empty:hidden">{{ $items->links() }}</div>
    </x-card>
</x-layouts.app>
