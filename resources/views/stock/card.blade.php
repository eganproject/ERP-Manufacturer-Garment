<x-layouts.app :title="'Kartu Stok: '.$title">
    <x-slot:actions>
        <a href="{{ url()->previous() }}" class="btn-secondary">Kembali</a>
    </x-slot:actions>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-gray-500">{{ $item instanceof \App\Models\ProductVariant ? 'Stok jual saat ini' : 'Stok saat ini' }}</p>
            <p class="mt-1 text-2xl font-semibold">{{ qty($item->stock) }} <span class="text-base font-normal text-gray-500">{{ $unit }}</span></p>
        </div>
        @if ($item instanceof \App\Models\ProductVariant && ((float) $item->defect_stock > 0 || $movements->contains(fn ($m) => $m->location === \App\Enums\StockLocation::Defect)))
            <div class="rounded-xl border border-red-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-gray-500">Stok cacat (karantina)</p>
                <p class="mt-1 text-2xl font-semibold text-red-600">{{ qty($item->defect_stock) }} <span class="text-base font-normal text-gray-500">{{ $unit }}</span></p>
                <a href="{{ route('defects.index') }}" class="text-xs text-indigo-600 hover:underline">Proses barang cacat</a>
            </div>
        @endif
    </div>

    <x-card title="Mutasi Stok" :padding="false">
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>Tanggal</th><th>Jenis</th><th>Referensi</th><th>Keterangan</th><th class="text-right">Masuk</th><th class="text-right">Keluar</th><th class="text-right">Saldo</th><th>Oleh</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($movements as $mv)
                        <tr>
                            <td class="whitespace-nowrap">{{ tanggal($mv->date) }}</td>
                            <td>{{ $mv->type->label() }}@if ($mv->location === \App\Enums\StockLocation::Defect) <x-badge color="red">Stok Cacat</x-badge>@endif</td>
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
