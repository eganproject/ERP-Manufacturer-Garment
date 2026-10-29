@use('App\Enums\DefectHandlingType')
<x-layouts.app title="Barang Cacat">
    <x-slot:actions>
        @can('defects.manage')
            @foreach (DefectHandlingType::cases() as $type)
                <a href="{{ route('defects.create', ['type' => $type->value]) }}" class="{{ $type === DefectHandlingType::Rework ? 'btn-primary' : 'btn-secondary' }}">+ {{ $type->label() }}</a>
            @endforeach
        @endcan
    </x-slot:actions>

    <x-summary-tiles :tiles="[
        ['Stok cacat (karantina)', qty($totalQty).' pcs', $totalQty > 0 ? 'amber' : null],
        ['Nilai stok cacat (HPP)', rupiah($totalValue)],
        ['Jenis barang', (string) $stock->count()],
    ]" />

    <x-card title="Stok Cacat Menunggu Diproses" :padding="false" class="mb-6">
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>SKU</th><th>Produk</th><th class="text-right">Stok Cacat</th><th class="text-right">HPP / pcs</th><th class="text-right">Nilai</th><th class="text-right">Stok Jual</th><th class="text-right">Aksi</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($stock as $v)
                        <tr>
                            <td class="font-medium">{{ $v->sku }}</td>
                            <td>{{ $v->display_name }}</td>
                            <td class="text-right font-semibold text-red-600">{{ qty($v->defect_stock) }} {{ $v->product->unit?->code }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($v->defect_cost) }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah((float) $v->defect_stock * (float) $v->defect_cost) }}</td>
                            <td class="text-right">{{ qty($v->stock) }}</td>
                            <td class="space-x-2 text-right whitespace-nowrap">
                                <a href="{{ route('stock.products.card', $v) }}" class="text-sm text-gray-600 hover:underline">Kartu Stok</a>
                                @can('defects.manage')
                                    <a href="{{ route('defects.create', ['type' => 'rework', 'variant_id' => $v->id]) }}" class="text-sm text-indigo-600 hover:underline">Perbaiki</a>
                                    <a href="{{ route('defects.create', ['type' => 'scrap', 'variant_id' => $v->id]) }}" class="text-sm text-red-600 hover:underline">Musnahkan</a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <x-empty-row colspan="7" message="Tidak ada barang cacat. Barang retur berkondisi cacat otomatis masuk ke sini." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <x-card title="Riwayat Penanganan" :padding="false">
        <div class="border-b border-gray-100 p-4">
            <x-search-filter placeholder="Cari no. / catatan...">
                <div class="w-56"><x-form.select name="type" :options="DefectHandlingType::options()" :value="request('type')" placeholder="Semua jenis" /></div>
            </x-search-filter>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>No.</th><th>Tanggal</th><th>Jenis</th><th>Barang</th><th class="text-right">Qty</th><th class="text-right">Nilai</th><th>Status</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($handlings as $h)
                        <tr>
                            <td><a href="{{ route('defects.show', $h) }}" class="font-medium text-indigo-600 hover:underline">{{ $h->number }}</a></td>
                            <td class="whitespace-nowrap">{{ tanggal($h->date) }}</td>
                            <td><x-badge :color="$h->type->color()">{{ $h->type->label() }}</x-badge></td>
                            <td class="text-sm">{{ $h->items->map(fn ($i) => $i->variant->display_name)->implode(', ') }}@if ($h->notes)<div class="text-xs text-gray-500">{{ $h->notes }}</div>@endif</td>
                            <td class="text-right">{{ qty($h->totalQuantity()) }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah((float) $h->total_cost + (float) $h->extra_cost) }}</td>
                            <td><x-status-badge :status="$h->status" /></td>
                        </tr>
                    @empty
                        <x-empty-row colspan="7" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-4 py-3 empty:hidden">{{ $handlings->links() }}</div>
    </x-card>
</x-layouts.app>
