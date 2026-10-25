<x-layouts.app title="Gudang">
    <x-slot:actions>
        @can('stock.transfer')<a href="{{ route('stock-transfers.create') }}" class="btn-secondary">Transfer Antar Gudang</a>@endcan
        @can('warehouses.manage')<a href="{{ route('warehouses.create') }}" class="btn-primary">+ Tambah Gudang</a>@endcan
    </x-slot:actions>

    <x-card :padding="false">
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>Kode</th><th>Gudang</th><th class="text-right">Jenis Bahan</th><th class="text-right">Jenis Barang Jadi</th><th class="text-right">Stok Cacat</th>@can('costing.view')<th class="text-right">Nilai Stok</th>@endcan<th>Status</th><th></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($warehouses as $warehouse)
                        @php $rows = ($summary[$warehouse->id] ?? collect())->keyBy('stockable_type'); @endphp
                        <tr @class(['opacity-60' => ! $warehouse->is_active])>
                            <td class="font-medium">{{ $warehouse->code }}</td>
                            <td><a href="{{ route('warehouses.show', $warehouse) }}" class="font-medium text-indigo-600 hover:underline">{{ $warehouse->name }}</a>
                                @if ($warehouse->is_default) <x-badge color="indigo">Default</x-badge>@endif
                                @if ($warehouse->address)<div class="text-xs text-gray-500">{{ $warehouse->address }}</div>@endif</td>
                            <td class="text-right">{{ (int) ($rows['material']->items ?? 0) }}</td>
                            <td class="text-right">{{ (int) ($rows['product_variant']->items ?? 0) }} <span class="text-xs text-gray-500">({{ qty($rows['product_variant']->qty ?? 0) }} pcs)</span></td>
                            <td class="text-right">{{ qty($rows['product_variant']->defect ?? 0) }}</td>
                            @can('costing.view')<td class="text-right whitespace-nowrap">{{ rupiah($values[$warehouse->id] ?? 0) }}</td>@endcan
                            <td><x-badge :color="$warehouse->is_active ? 'green' : 'gray'">{{ $warehouse->is_active ? 'Aktif' : 'Nonaktif' }}</x-badge></td>
                            <td class="text-right whitespace-nowrap">
                                @can('warehouses.manage')<a href="{{ route('warehouses.edit', $warehouse) }}" class="text-sm text-indigo-600 hover:underline">Ubah</a>@endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="border-t border-gray-100 px-5 py-3 text-xs text-gray-500">
            Penerimaan, pengiriman, SPK, maklon, retur & barang cacat memilih gudangnya; tanpa pilihan = gudang default.
            Harga pokok rata-rata berlaku sama untuk semua gudang.
        </p>
    </x-card>
</x-layouts.app>
