<x-layouts.app title="Bahan Baku">
    <x-slot:actions><a href="{{ route('materials.create') }}" class="btn-primary">+ Tambah Bahan</a></x-slot:actions>

    <x-card :padding="false">
        <div class="border-b border-gray-200 p-4">
            <x-search-filter placeholder="Cari kode / nama...">
                <div class="w-52">
                    <x-form.select name="category_id" :options="$categories->pluck('name', 'id')" :value="request('category_id')" placeholder="Semua kategori" />
                </div>
                <label class="inline-flex items-center gap-2 py-2 text-sm text-gray-700">
                    <input type="checkbox" name="low_stock" value="1" class="rounded border-gray-300 text-indigo-600" @checked(request('low_stock'))> Stok menipis
                </label>
            </x-search-filter>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>Kode</th><th>Nama</th><th>Kategori</th><th class="text-right">Harga</th><th class="text-right">Stok</th><th>Status</th><th class="text-right">Aksi</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($materials as $material)
                        <tr>
                            <td class="font-medium">{{ $material->code }}</td>
                            <td>{{ $material->name }}@if ($material->color)<div class="text-xs text-gray-500">Warna: {{ $material->color }}</div>@endif</td>
                            <td>{{ $material->category?->name ?? '-' }}</td>
                            <td class="text-right">{{ rupiah($material->price) }}</td>
                            <td @class(['text-right font-medium', 'text-red-600' => $material->isLowStock()])>
                                <a href="{{ route('stock.materials.card', $material) }}" class="hover:underline">{{ qty($material->stock) }} {{ $material->unit->code }}</a>
                            </td>
                            <td><x-badge :color="$material->is_active ? 'green' : 'gray'">{{ $material->is_active ? 'Aktif' : 'Nonaktif' }}</x-badge></td>
                            <td class="space-x-3 text-right whitespace-nowrap">
                                <a href="{{ route('materials.edit', $material) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Ubah</a>
                                <x-delete-button :action="route('materials.destroy', $material)" />
                            </td>
                        </tr>
                    @empty
                        <x-empty-row colspan="7" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-4 py-3 empty:hidden">{{ $materials->links() }}</div>
    </x-card>
</x-layouts.app>
