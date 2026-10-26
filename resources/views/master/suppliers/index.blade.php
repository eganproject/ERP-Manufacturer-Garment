<x-layouts.app title="Supplier">
    <x-slot:actions><a href="{{ route('suppliers.create') }}" class="btn-primary">+ Tambah Supplier</a></x-slot:actions>

    <x-card :padding="false">
        <div class="border-b border-gray-200 p-4"><x-search-filter placeholder="Cari kode / nama / telepon...">
            <div class="w-48"><x-form.select name="type" :options="['subcontractor' => 'Vendor maklon']" :value="request('type')" placeholder="Semua supplier" /></div>
        </x-search-filter></div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>Kode</th><th>Nama</th><th>Kontak</th><th>Telepon</th><th>Status</th><th class="text-right">Aksi</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($suppliers as $supplier)
                        <tr>
                            <td class="font-medium">{{ $supplier->code }}</td>
                            <td>{{ $supplier->name }} @if ($supplier->is_subcontractor)<x-badge color="indigo">Maklon</x-badge>@endif<div class="text-xs text-gray-500">{{ $supplier->email }}</div></td>
                            <td>{{ $supplier->contact_person ?: '-' }}</td>
                            <td>{{ $supplier->phone ?: '-' }}</td>
                            <td><x-badge :color="$supplier->is_active ? 'green' : 'gray'">{{ $supplier->is_active ? 'Aktif' : 'Nonaktif' }}</x-badge></td>
                            <td class="space-x-3 text-right whitespace-nowrap">
                                <a href="{{ route('suppliers.edit', $supplier) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Ubah</a>
                                <x-delete-button :action="route('suppliers.destroy', $supplier)" />
                            </td>
                        </tr>
                    @empty
                        <x-empty-row colspan="6" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-4 py-3 empty:hidden">{{ $suppliers->links() }}</div>
    </x-card>
</x-layouts.app>
