<x-layouts.app title="Pencairan Dana Marketplace">
    <x-slot:actions>
        <a href="{{ route('marketplace-settlements.template') }}" class="btn-secondary">Template CSV</a>
        @can('marketplace.settle')<a href="{{ route('marketplace-settlements.create') }}" class="btn-primary">Impor Laporan Penghasilan</a>@endcan
    </x-slot:actions>

    <x-card :padding="false">
        <div class="border-b border-gray-200 p-4">
            <x-search-filter placeholder="Cari no. pencairan...">
                <div class="w-64"><x-form.select name="store_id" :options="$stores" :value="request('store_id')" placeholder="Semua toko" /></div>
                <div class="w-40"><x-form.select name="status" :options="$statuses" :value="request('status')" placeholder="Semua status" /></div>
            </x-search-filter>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>No.</th><th>Tanggal</th><th>Toko</th><th class="text-right">Baris</th><th class="text-right">Nilai Pesanan</th><th class="text-right">Voucher + Biaya + Ongkir</th><th class="text-right">Dana Bersih</th><th>Status</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($settlements as $settlement)
                        <tr>
                            <td><a href="{{ route('marketplace-settlements.show', $settlement) }}" class="font-medium text-indigo-600 hover:underline">{{ $settlement->number }}</a></td>
                            <td class="whitespace-nowrap">{{ tanggal($settlement->date) }}</td>
                            <td>{{ $settlement->store->display_name }}</td>
                            <td class="text-right">{{ $settlement->lines_count }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($settlement->order_amount) }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah((float) $settlement->voucher + (float) $settlement->fees + (float) $settlement->shipping_fee) }}</td>
                            <td class="text-right font-medium whitespace-nowrap">{{ rupiah($settlement->net_amount) }}</td>
                            <td><x-status-badge :status="$settlement->status" /></td>
                        </tr>
                    @empty
                        <x-empty-row colspan="8" message="Belum ada pencairan dana." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-4 py-3 empty:hidden">{{ $settlements->links() }}</div>
    </x-card>
</x-layouts.app>
