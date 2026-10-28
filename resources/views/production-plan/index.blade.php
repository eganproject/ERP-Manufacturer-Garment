@php
    $canProduce = auth()->user()->can('production.manage');
    $totalNet = $groups->sum('net');
    $urgentDate = today()->addDays(\App\Services\Marketplace\MarketplaceFulfillment::URGENT_DAYS);
@endphp
<x-layouts.app title="Rencana Produksi" subtitle="Kekurangan barang jadi dari pesanan marketplace (PO & ready stock) dan SO">
    <x-slot:actions>
        @can('marketplace.view')
            <a href="{{ route('marketplace-orders.index', ['status' => 'waiting']) }}" class="btn-secondary">Pesanan Menunggu Produksi</a>
        @endcan
        @can('mrp.view')
            <a href="{{ route('mrp.index') }}" class="btn-secondary">Kebutuhan Bahan (MRP)</a>
        @endcan
    </x-slot:actions>

    <x-guide title="Alur pesanan PO marketplace ke produksi" :steps="[
        '**Impor pesanan** dari Seller Center. Pesanan PO & batas kirimnya terbaca dari file atau dari **hari PO** di Mapping SKU.',
        'Stok gudang toko dibagikan ke pesanan mulai **batas kirim terdekat**. Yang tidak kebagian menjadi **Menunggu Produksi** dan dihitung di halaman ini.',
        'Kekurangan = pesanan marketplace + sisa SO − stok jadi − SPK yang sedang berjalan. Centang artikel, periksa jumlah per ukuran, lalu klik **Buat SPK Draft**.',
        'Cek **MRP** untuk membeli bahan yang kurang, lalu **Mulai Produksi** di SPK.',
        'Saat SPK selesai, barang masuk gudang dan otomatis dibagikan ke pesanan: pesanan pindah ke **Siap Dikirim**. Cetak **Daftar Ambil Barang** lalu kirim.',
    ]" />

    <x-summary-tiles :tiles="[
        ['Pesanan menunggu produksi', $waiting.' pesanan', $waiting ? 'amber' : null],
        ['Batas kirim ≤ '.\App\Services\Marketplace\MarketplaceFulfillment::URGENT_DAYS.' hari', $urgent.' pesanan', $urgent ? 'red' : null],
        ['Pesanan siap dikirim', $ready.' pesanan', $ready ? 'green' : null],
        ['Perlu diproduksi', qty($totalNet).' pcs', $totalNet > 0 ? 'amber' : null],
    ]" />

    <form method="POST" action="{{ route('production-plan.store') }}" x-data="{ selected: [] }"
          data-confirm="SPK draft dibuat per artikel yang dicentang. Bahan belum dikeluarkan sampai SPK dimulai." data-confirm-icon="question" data-confirm-button="Ya, buat SPK draft">
        @csrf
        <x-card :padding="false" title="Kebutuhan Produksi per Artikel">
            <x-slot:header>
                <div class="flex flex-wrap items-center gap-3">
                    <a href="{{ route('production-plan.index', $showAll ? [] : ['all' => 1]) }}" class="text-sm text-gray-500 hover:text-gray-800">{{ $showAll ? 'Sembunyikan yang sudah cukup' : 'Tampilkan semua artikel' }}</a>
                    @if ($canProduce && $groups->isNotEmpty())
                        <div class="w-56"><x-form.warehouse :value="$warehouseId" label="" tip="Gudang tempat hasil SPK masuk. Pilih gudang toko marketplace agar hasilnya langsung dibagikan ke pesanan." /></div>
                        <button type="submit" class="btn-primary" :disabled="! selected.length">Buat SPK Draft <span x-show="selected.length" x-text="'(' + selected.length + ')'"></span></button>
                    @endif
                </div>
            </x-slot:header>
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr>
                        @if ($canProduce)<th class="w-8"></th>@endif
                        <th>Artikel / Ukuran</th>
                        <th class="text-right"><x-tip title="Marketplace" text="Pesanan TikTok Shop / Shopee berstatus Perlu Dikirim (sudah dibayar) yang SKU-nya sudah dipetakan. Angka PO = bagian pre-order." />Marketplace</th>
                        <th class="text-right">Sisa SO</th>
                        <th class="text-right">Stok Jadi</th>
                        <th class="text-right">Dalam SPK</th>
                        <th class="text-right">Kekurangan</th>
                        <th><x-tip title="Batas kirim terdekat" text="Tanggal paling awal pesanan yang belum kebagian stok harus dikirim (marketplace) atau tanggal kirim SO." />Batas Kirim</th>
                        @if ($canProduce)<th class="w-36"><x-tip title="Jumlah SPK" text="Jumlah yang akan diproduksi per ukuran. Default = kekurangan; boleh dinaikkan (mis. dibulatkan per ikat potong) atau dikurangi." />Jumlah SPK</th>@endif
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($groups as $group)
                            @php
                                $product = $group['product'];
                                $hasBom = (bool) $product->activeBom;
                                $due = \App\Services\ProductionPlanner::dueDateFor($group['ship_by']);
                            @endphp
                            <tr class="bg-gray-50/70">
                                @if ($canProduce)
                                    <td>@if ($hasBom && $group['net'] > 0)<input type="checkbox" name="selected[]" value="{{ $product->id }}" x-model="selected" class="rounded border-gray-300">@endif</td>
                                @endif
                                <td colspan="5" class="font-semibold text-gray-900">
                                    {{ $product->code }} - {{ $product->name }}
                                    @unless ($hasBom)<span class="ml-2 text-xs font-normal text-red-600">Belum ada BOM aktif</span>@endunless
                                </td>
                                <td class="text-right font-semibold {{ $group['net'] > 0 ? 'text-red-600' : 'text-gray-400' }}">{{ qty($group['net']) }}</td>
                                <td class="whitespace-nowrap">
                                    @if ($group['ship_by'])
                                        <span @class(['font-medium', 'text-red-600' => $group['ship_by']->lte($urgentDate)])>{{ tanggal($group['ship_by']) }}</span>
                                    @else
                                        <span class="text-gray-400">-</span>
                                    @endif
                                </td>
                                @if ($canProduce)
                                    <td>
                                        @if ($hasBom && $group['net'] > 0)
                                            <input type="text" name="due[{{ $product->id }}]" value="{{ $due?->toDateString() }}" data-date class="form-control" placeholder="Target selesai" title="Target selesai SPK">
                                        @endif
                                    </td>
                                @endif
                            </tr>
                            @foreach ($group['variants'] as $row)
                                <tr>
                                    @if ($canProduce)<td></td>@endif
                                    <td class="pl-8">{{ $row['variant']->sku }} <span class="text-gray-500">· {{ $row['variant']->size }}{{ $row['variant']->color ? ' / '.$row['variant']->color : '' }}</span></td>
                                    <td class="text-right">{{ qty($row['marketplace']) }}@if ($row['preorder'] > 0)<div class="text-xs text-gray-500">PO {{ qty($row['preorder']) }}</div>@endif</td>
                                    <td class="text-right">{{ qty($row['sales']) }}</td>
                                    <td class="text-right">{{ qty($row['stock']) }}</td>
                                    <td class="text-right">{{ qty($row['planned']) }}</td>
                                    <td @class(['text-right font-medium', 'text-red-600' => $row['net'] > 0, 'text-gray-400' => $row['net'] <= 0])>{{ qty($row['net']) }}</td>
                                    <td class="whitespace-nowrap text-sm text-gray-600">{{ $row['ship_by'] ? tanggal($row['ship_by']) : '' }}</td>
                                    @if ($canProduce)
                                        <td>@if ($hasBom)<input type="number" min="0" step="1" name="products[{{ $product->id }}][{{ $row['variant']->id }}]" value="{{ (int) ceil($row['net']) }}" class="form-control">@endif</td>
                                    @endif
                                </tr>
                            @endforeach
                        @empty
                            <x-empty-row :colspan="$canProduce ? 9 : 7" message="Semua pesanan marketplace & SO sudah tercukupi stok atau SPK berjalan." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    </form>

    <p class="mt-3 text-xs text-gray-500">
        Pesanan Belum Dibayar tidak dihitung. Stok jadi = stok jual semua gudang; SPK berjalan = SPK draft & dalam proses (qty belum selesai).
        Target selesai SPK diisi otomatis 1 hari sebelum batas kirim terdekat.
    </p>
</x-layouts.app>
