<x-layouts.app title="Bill of Materials">
    <x-slot:actions><a href="{{ route('boms.create') }}" class="btn-primary">+ Buat BOM</a></x-slot:actions>

    <x-card :padding="false">
        <div class="border-b border-gray-100 p-4"><x-search-filter placeholder="Cari nama BOM / produk..." /></div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>Nama BOM</th><th>Produk</th><th class="text-right">Jumlah Bahan</th><th>Status</th><th class="text-right">Aksi</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($boms as $bom)
                        <tr>
                            <td><a href="{{ route('boms.show', $bom) }}" class="font-medium text-indigo-600 hover:underline">{{ $bom->name }}</a></td>
                            <td>{{ $bom->product->code }} - {{ $bom->product->name }}</td>
                            <td class="text-right">{{ $bom->items_count }}</td>
                            <td><x-badge :color="$bom->is_active ? 'green' : 'gray'">{{ $bom->is_active ? 'Aktif' : 'Nonaktif' }}</x-badge></td>
                            <td class="space-x-3 text-right whitespace-nowrap">
                                <a href="{{ route('boms.edit', $bom) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Ubah</a>
                                <x-delete-button :action="route('boms.destroy', $bom)" />
                            </td>
                        </tr>
                    @empty
                        <x-empty-row colspan="5" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-4 py-3 empty:hidden">{{ $boms->links() }}</div>
    </x-card>
</x-layouts.app>
