<x-layouts.app :title="'Kartu Stok: '.$title">
    <x-slot:actions>
        <a href="{{ url()->previous() }}" class="btn-secondary">Kembali</a>
    </x-slot:actions>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-gray-500">Stok saat ini</p>
            <p class="mt-1 text-2xl font-semibold">{{ qty($item->stock) }} <span class="text-base font-normal text-gray-500">{{ $unit }}</span></p>
        </div>
    </div>

    <x-card title="Mutasi Stok" :padding="false">
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>Tanggal</th><th>Jenis</th><th>Referensi</th><th>Keterangan</th><th class="text-right">Masuk</th><th class="text-right">Keluar</th><th class="text-right">Saldo</th><th>Oleh</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($movements as $mv)
                        <tr>
                            <td class="whitespace-nowrap">{{ tanggal($mv->date) }}</td>
                            <td>{{ $mv->type->label() }}</td>
                            <td>{{ $mv->reference_number ?: '-' }}</td>
                            <td>{{ $mv->notes }}</td>
                            <td class="text-right text-emerald-600">{{ $mv->quantity > 0 ? qty($mv->quantity) : '' }}</td>
                            <td class="text-right text-red-600">{{ $mv->quantity < 0 ? qty(abs($mv->quantity)) : '' }}</td>
                            <td class="text-right font-semibold">{{ qty($mv->balance) }}</td>
                            <td>{{ $mv->user?->name ?? '-' }}</td>
                        </tr>
                    @empty
                        <x-empty-row colspan="8" message="Belum ada mutasi stok." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4">{{ $movements->links() }}</div>
    </x-card>
</x-layouts.app>
