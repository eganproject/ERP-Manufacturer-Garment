<x-layouts.app title="Satuan">
    <x-slot:actions><a href="{{ route('units.create') }}" class="btn-primary">+ Tambah Satuan</a></x-slot:actions>

    <x-card :padding="false">
        <div class="border-b border-gray-200 p-4"><x-search-filter placeholder="Cari kode / nama..." /></div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>Kode</th><th>Nama</th><th class="text-right">Aksi</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($units as $unit)
                        <tr>
                            <td class="font-medium">{{ $unit->code }}</td>
                            <td>{{ $unit->name }}</td>
                            <td class="space-x-3 text-right whitespace-nowrap">
                                <a href="{{ route('units.edit', $unit) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Ubah</a>
                                <x-delete-button :action="route('units.destroy', $unit)" />
                            </td>
                        </tr>
                    @empty
                        <x-empty-row colspan="3" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4">{{ $units->links() }}</div>
    </x-card>
</x-layouts.app>
