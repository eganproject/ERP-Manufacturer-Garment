<x-layouts.app title="Karyawan Produksi">
    <x-slot:actions>
        @can('employees.manage')
            <a href="{{ route('piece-rates.index') }}" class="btn-secondary">Tarif Borongan</a>
            <a href="{{ route('employees.create') }}" class="btn-primary">+ Tambah Karyawan</a>
        @endcan
    </x-slot:actions>

    <x-card :padding="false">
        <div class="border-b border-gray-200 p-4">
            <x-search-filter placeholder="Cari kode / nama / telepon...">
                <div class="w-52"><x-form.select name="type" :options="$types" :value="request('type')" placeholder="Semua pola kerja" /></div>
                <div class="w-40"><x-form.select name="status" :options="['active' => 'Aktif', 'inactive' => 'Nonaktif']" :value="request('status')" placeholder="Semua status" /></div>
            </x-search-filter>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>Kode</th><th>Nama</th><th>Pola Kerja</th><th>Telepon</th><th class="text-right">Upah Belum Dibayar</th><th>Status</th><th class="text-right">Aksi</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($employees as $employee)
                        <tr>
                            <td class="font-medium">{{ $employee->code }}</td>
                            <td><a href="{{ route('employees.show', $employee) }}" class="font-medium text-indigo-600 hover:underline">{{ $employee->name }}</a></td>
                            <td><x-badge :color="$employee->work_type->color()">{{ $employee->work_type->label() }}</x-badge></td>
                            <td>{{ $employee->phone ?: '-' }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($employee->unpaid_amount ?? 0) }}</td>
                            <td><x-badge :color="$employee->is_active ? 'green' : 'gray'">{{ $employee->is_active ? 'Aktif' : 'Nonaktif' }}</x-badge></td>
                            <td class="space-x-3 text-right whitespace-nowrap">
                                @can('employees.manage')
                                    <a href="{{ route('employees.edit', $employee) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Ubah</a>
                                    <x-delete-button :action="route('employees.destroy', $employee)" />
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <x-empty-row colspan="7" message="Belum ada karyawan produksi. Tambahkan karyawan borongan (per pcs jadi atau per operasi)." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-4 py-3 empty:hidden">{{ $employees->links() }}</div>
    </x-card>
</x-layouts.app>
