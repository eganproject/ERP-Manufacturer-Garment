<x-layouts.app title="Mapping SKU Marketplace">
    @can('marketplace.manage')
        <x-slot:actions>
            <x-action-form :action="route('marketplace-mappings.auto', ['store_id' => $storeId])" confirm="SKU marketplace yang sama persis dengan SKU varian ERP akan dipetakan otomatis.">Petakan Otomatis (SKU sama)</x-action-form>
        </x-slot:actions>
    @endcan

    @if ($unmapped->isNotEmpty())
        <x-card title="SKU di pesanan yang belum dipetakan" :padding="false" class="mb-6 border-amber-300">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>Toko</th><th>SKU Marketplace</th><th>Produk di Marketplace</th><th class="text-right">Pesanan</th>@can('marketplace.manage')<th class="w-[32rem]">Petakan ke Varian ERP <span class="font-normal text-gray-400">· isi/paket · hari PO</span></th>@endcan</tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($unmapped as $row)
                            <tr>
                                <td class="whitespace-nowrap">{{ $stores[$row->store_id] ?? '-' }}</td>
                                <td class="font-medium">{{ $row->sku ?: '(tanpa SKU)' }}</td>
                                <td>{{ $row->name }}@if ($row->variation)<div class="text-xs text-gray-500">{{ $row->variation }}</div>@endif</td>
                                <td class="text-right">{{ $row->orders }}</td>
                                @can('marketplace.manage')
                                    <td>
                                        @if ($row->sku)
                                            <form method="POST" action="{{ route('marketplace-mappings.store') }}" class="flex items-center gap-2">
                                                @csrf
                                                <input type="hidden" name="marketplace_store_id" value="{{ $row->store_id }}">
                                                <input type="hidden" name="marketplace_sku" value="{{ $row->sku }}">
                                                <input type="hidden" name="marketplace_name" value="{{ $row->name }}">
                                                <div class="flex-1"><x-form.select name="product_variant_id" :options="$variants" data-select :id="'v'.$loop->index" /></div>
                                                <input type="number" name="units_per_item" value="1" min="0.01" step="0.01" class="form-control w-20" title="Isi per paket (pcs)">
                                                <input type="number" name="preorder_days" value="0" min="0" max="90" step="1" class="form-control w-20" title="Hari PO (0 = ready stock)">
                                                <button type="submit" class="btn-primary btn-sm">Simpan</button>
                                            </form>
                                        @else
                                            <span class="text-xs text-gray-500">Isi SKU penjual di Seller Center agar bisa dipetakan.</span>
                                        @endif
                                    </td>
                                @endcan
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card :padding="false" class="lg:col-span-2">
            <div class="border-b border-gray-100 p-4">
                <x-search-filter placeholder="Cari SKU / nama...">
                    <div class="w-64"><x-form.select name="store_id" :options="$stores" :value="$storeId" placeholder="Semua toko" /></div>
                    <div class="w-40"><x-form.select name="type" :options="['preorder' => 'PO', 'ready' => 'Ready stock']" :value="request('type')" placeholder="Semua jenis" :native="true" /></div>
                </x-search-filter>
            </div>
            @can('marketplace.manage')
                <form method="POST" action="{{ route('marketplace-mappings.preorder') }}" id="bulk-preorder" class="flex flex-wrap items-center gap-2 border-b border-gray-200 bg-gray-50/60 px-4 py-2.5 text-sm">
                    @csrf
                    <span class="text-gray-600">SKU dicentang:</span>
                    <input type="number" name="preorder_days" value="30" min="0" max="90" class="form-control w-20">
                    <span class="text-gray-600">hari PO</span>
                    <x-tip title="Hari PO" text="Lama pre-order di Seller Center. Batas kirim pesanan = tanggal bayar + hari PO. Isi 0 untuk ready stock (batas kirim = hari proses toko)." />
                    <button type="submit" class="btn-secondary btn-sm">Terapkan</button>
                </form>
            @endcan
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr>@can('marketplace.manage')<th class="w-8"></th>@endcan<th>Toko</th><th>SKU Marketplace</th><th>Varian ERP</th><th class="text-right">Isi/Paket</th><th>Jenis</th><th></th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($mappings as $mapping)
                            <tr>
                                @can('marketplace.manage')<td><input type="checkbox" name="mappings[]" value="{{ $mapping->id }}" form="bulk-preorder" class="rounded border-gray-300"></td>@endcan
                                <td class="whitespace-nowrap">{{ $mapping->store->display_name }}</td>
                                <td class="font-medium">{{ $mapping->marketplace_sku }}@if ($mapping->marketplace_name)<div class="text-xs text-gray-500">{{ $mapping->marketplace_name }}</div>@endif</td>
                                <td>{{ $mapping->variant->sku }}<div class="text-xs text-gray-500">{{ $mapping->variant->display_name }}</div></td>
                                <td class="text-right">{{ qty($mapping->units_per_item) }}</td>
                                <td class="whitespace-nowrap">@if ($mapping->preorder_days)<x-badge color="indigo">PO {{ $mapping->preorder_days }} hari</x-badge>@else<span class="text-sm text-gray-500">Ready stock</span>@endif</td>
                                <td class="text-right">@can('marketplace.manage')<x-delete-button :action="route('marketplace-mappings.destroy', $mapping)" class="text-sm text-red-600 hover:underline" />@endcan</td>
                            </tr>
                        @empty
                            <x-empty-row colspan="7" message="Belum ada mapping SKU." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-gray-100 px-4 py-3 empty:hidden">{{ $mappings->links() }}</div>
        </x-card>

        @can('marketplace.manage')
            <x-card title="Tambah / Ubah Mapping">
                <form method="POST" action="{{ route('marketplace-mappings.store') }}" class="space-y-4">
                    @csrf
                    <x-form.select name="marketplace_store_id" label="Toko" :options="$stores" :value="$storeId" required />
                    <x-form.input name="marketplace_sku" label="SKU Marketplace" required help="SKU penjual (Seller SKU / Nomor Referensi SKU)." tip="Kode SKU yang Anda isi di Seller Center. Harus sama persis dengan yang muncul di file pesanan." />
                    <x-form.input name="marketplace_name" label="Nama di Marketplace" />
                    <x-form.select name="product_variant_id" label="Varian ERP" tip="Varian produk di ERP yang dikirim saat SKU marketplace ini terjual. Stoknya yang akan berkurang." :options="$variants" required data-select />
                    <x-form.input name="units_per_item" label="Isi per Paket (pcs)" value="1" type="number" step="0.01" min="0.01" help="Lebih dari 1 untuk paket bundling, mis. 1 SKU = 3 pcs." tip="Berapa pcs varian ERP dalam 1 unit SKU marketplace. Mis. paket isi 3 → setiap 1 pesanan mengurangi stok 3 pcs." />
                    <x-form.input name="preorder_days" label="Hari PO" value="0" type="number" min="0" max="90" help="0 = ready stock." tip="Isi sesuai lama pre-order produk ini di Seller Center (mis. 30). Batas kirim pesanan = tanggal bayar + hari PO, dan pesanan ditandai PO di rencana produksi." />
                    <button type="submit" class="btn-primary w-full">Simpan Mapping</button>
                </form>
            </x-card>
        @endcan
    </div>
</x-layouts.app>
