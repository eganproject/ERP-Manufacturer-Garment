<x-layouts.app title="Toko Marketplace">
    @can('marketplace.manage')
        <x-slot:actions><a href="{{ route('marketplace-stores.create') }}" class="btn-primary">+ Tambah Toko</a></x-slot:actions>
    @endcan

    <x-card :padding="false">
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>Platform</th><th>Toko</th><th>Gudang Stok</th><th>Rekening Saldo Penjual</th><th class="text-right">PPN</th><th class="text-right">Mapping SKU</th><th class="text-right">Pesanan</th><th>API</th><th>Status</th><th></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($stores as $store)
                        <tr>
                            <td><x-badge :color="$store->platform->color()">{{ $store->platform->label() }}</x-badge></td>
                            <td class="font-medium">{{ $store->name }}@if ($store->shop_id)<div class="text-xs text-gray-500">ID {{ $store->shop_id }}</div>@endif</td>
                            <td>{{ $store->warehouse->name }}</td>
                            <td class="text-sm">{{ $store->balanceAccountName() }}</td>
                            <td class="text-right">{{ (float) $store->tax_rate ? qty($store->tax_rate).'%' : '-' }}</td>
                            <td class="text-right"><a href="{{ route('marketplace-mappings.index', ['store_id' => $store->id]) }}" class="text-indigo-600 hover:underline">{{ $store->mappings_count }}</a></td>
                            <td class="text-right whitespace-nowrap">
                                <a href="{{ route('marketplace-orders.index', ['store_id' => $store->id]) }}" class="text-indigo-600 hover:underline">{{ $store->orders_count }}</a>
                                @if ($store->to_ship_count)<div class="text-xs text-amber-600">{{ $store->to_ship_count }} perlu dikirim</div>@endif
                            </td>
                            <td class="text-sm">
                                @if ($store->hasApiCredentials())
                                    <span class="text-green-700">Aktif</span>
                                    <div class="text-xs text-gray-500">{{ $store->last_synced_at ? 'Sinkron '.$store->last_synced_at->diffForHumans() : 'Belum sinkron' }}</div>
                                @else
                                    <span class="text-gray-400">Impor file</span>
                                @endif
                            </td>
                            <td><x-badge :color="$store->is_active ? 'green' : 'gray'">{{ $store->is_active ? 'Aktif' : 'Nonaktif' }}</x-badge></td>
                            <td class="text-right whitespace-nowrap">
                                @can('marketplace.manage')
                                    @if ($store->hasApiCredentials())
                                        <x-action-form :action="route('marketplace-stores.sync', $store)" class="btn-secondary !py-1 text-xs">Sinkron API</x-action-form>
                                    @endif
                                    <a href="{{ route('marketplace-stores.edit', $store) }}" class="text-indigo-600 hover:underline">Ubah</a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <x-empty-row colspan="10" message="Belum ada toko. Tambahkan toko TikTok Shop / Shopee Anda." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <p class="mt-4 text-sm text-gray-500">
        Alur: pesanan diimpor dari file ekspor Seller Center (atau API bila kredensial diisi) → stok dialokasikan dari gudang toko → saat dikirim stok keluar dan penjualan dicatat sebagai Piutang Marketplace →
        laporan penghasilan / pencairan dana diimpor untuk mencatat biaya admin, komisi, ongkir & voucher serta menutup piutang.
    </p>
</x-layouts.app>
