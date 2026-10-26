<x-layouts.app title="Kategori">
    <x-slot:actions><a href="{{ route('categories.create') }}" class="btn-primary">+ Tambah Kategori</a></x-slot:actions>

    <x-card :padding="false">
        <div class="border-b border-gray-200 p-4">
            <x-search-filter placeholder="Cari nama...">
                <div class="w-48">
                    <x-form.select name="type" :options="\App\Models\Category::TYPES" :value="request('type')" placeholder="Semua jenis" />
                </div>
            </x-search-filter>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>Nama</th><th>Jenis</th><th class="text-right">Aksi</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($categories as $category)
                        <tr>
                            <td class="font-medium">{{ $category->name }}</td>
                            <td><x-badge :color="$category->type === 'material' ? 'amber' : 'indigo'">{{ $category->type_label }}</x-badge></td>
                            <td class="space-x-3 text-right whitespace-nowrap">
                                <a href="{{ route('categories.edit', $category) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Ubah</a>
                                <x-delete-button :action="route('categories.destroy', $category)" />
                            </td>
                        </tr>
                    @empty
                        <x-empty-row colspan="3" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-4 py-3 empty:hidden">{{ $categories->links() }}</div>
    </x-card>
</x-layouts.app>
