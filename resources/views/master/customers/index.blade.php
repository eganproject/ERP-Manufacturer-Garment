<x-layouts.app title="Pelanggan">
    <x-slot:actions><a href="{{ route('customers.create') }}" class="btn-primary">+ Tambah Pelanggan</a></x-slot:actions>

    <x-card :padding="false">
        <div class="border-b border-gray-200 p-4"><x-search-filter placeholder="Cari kode / nama / telepon..." /></div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>Kode</th><th>Nama</th><th>Kontak</th><th>Telepon</th><th>Status</th><th class="text-right">Aksi</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($customers as $customer)
                        <tr>
                            <td class="font-medium">{{ $customer->code }}</td>
                            <td>{{ $customer->name }}<div class="text-xs text-gray-500">{{ $customer->email }}</div></td>
                            <td>{{ $customer->contact_person ?: '-' }}</td>
                            <td>{{ $customer->phone ?: '-' }}</td>
                            <td><x-badge :color="$customer->is_active ? 'green' : 'gray'">{{ $customer->is_active ? 'Aktif' : 'Nonaktif' }}</x-badge></td>
                            <td class="space-x-3 text-right whitespace-nowrap">
                                <a href="{{ route('customers.edit', $customer) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Ubah</a>
                                <x-delete-button :action="route('customers.destroy', $customer)" />
                            </td>
                        </tr>
                    @empty
                        <x-empty-row colspan="6" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4">{{ $customers->links() }}</div>
    </x-card>
</x-layouts.app>
