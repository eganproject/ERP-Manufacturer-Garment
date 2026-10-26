<x-layouts.app :title="'Kartu Stok: '.$title">
    <x-slot:actions>
        @if ($warehouses->count() > 1)
            <form method="GET" class="w-56">
                <x-form.select name="warehouse_id" :options="$warehouses->mapWithKeys(fn ($w) => [$w->id => $w->display_name])" :value="request('warehouse_id')" placeholder="Semua gudang" onchange="this.form.submit()" />
            </form>
        @endif
        <a href="{{ url()->previous() }}" class="btn-secondary">Kembali</a>
    </x-slot:actions>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm">
            <p class="text-sm text-gray-500">{{ $item instanceof \App\Models\ProductVariant ? 'Stok jual saat ini' : 'Stok saat ini' }}{{ $warehouse ? ' di '.$warehouse->name : '' }}</p>
            <p class="mt-1 text-2xl font-semibold">{{ qty($warehouse ? $item->stockIn($warehouse) : $item->stock) }} <span class="text-base font-normal text-gray-500">{{ $unit }}</span></p>
            @unless ($warehouse)@include('stock.partials.warehouse-breakdown')@endunless
        </div>
        @if ($item instanceof \App\Models\ProductVariant && ((float) $item->defect_stock > 0 || $movements->contains(fn ($m) => $m->location === \App\Enums\StockLocation::Defect)))
            <div class="rounded-xl border border-red-200 bg-white p-5 shadow-sm">
                <p class="text-sm text-gray-500">Stok cacat (karantina)</p>
                <p class="mt-1 text-2xl font-semibold text-red-600">{{ qty($warehouse ? $item->stockIn($warehouse, true) : $item->defect_stock) }} <span class="text-base font-normal text-gray-500">{{ $unit }}</span></p>
                <a href="{{ route('defects.index') }}" class="text-xs text-indigo-600 hover:underline">Proses barang cacat</a>
            </div>
        @endif
    </div>

    <x-card title="Mutasi Stok" :padding="false">
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>Tanggal</th><th>Jenis</th>@if ($warehouses->count() > 1)<th>Gudang</th>@endif<th>Referensi</th><th>Keterangan</th><th class="text-right">Masuk</th><th class="text-right">Keluar</th><th class="text-right">Saldo{{ $warehouse ? ' Gudang' : '' }}</th><th>Oleh</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($movements as $mv)
                        <tr>
                            <td class="whitespace-nowrap">{{ tanggal($mv->date) }}</td>
                            <td>{{ $mv->type->label() }}@if ($mv->location === \App\Enums\StockLocation::Defect) <x-badge color="red">Stok Cacat</x-badge>@endif</td>
                            @if ($warehouses->count() > 1)<td>{{ $mv->warehouse?->code ?? '-' }}</td>@endif
                            <td>{{ $mv->reference_number ?: '-' }}</td>
                            <td>{{ $mv->notes }}</td>
                            <td class="text-right text-emerald-600">{{ $mv->quantity > 0 ? qty($mv->quantity) : '' }}</td>
                            <td class="text-right text-red-600">{{ $mv->quantity < 0 ? qty(abs($mv->quantity)) : '' }}</td>
                            <td class="text-right font-semibold">{{ qty($warehouse ? $mv->warehouse_balance : $mv->balance) }}</td>
                            <td>{{ $mv->user?->name ?? '-' }}</td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="$warehouses->count() > 1 ? 9 : 8" message="Belum ada mutasi stok." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-4 py-3 empty:hidden">{{ $movements->links() }}</div>
    </x-card>
</x-layouts.app>
