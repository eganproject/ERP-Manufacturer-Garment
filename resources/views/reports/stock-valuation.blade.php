@use('App\Services\Reports\StockValuationReport')
@php
    $isProducts = $type === 'products';
@endphp
<x-layouts.app title="Nilai Persediaan">
    <x-card class="mb-6">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div class="w-44"><x-form.select name="type" label="Jenis" :options="StockValuationReport::TYPES" :value="$type" :placeholder="false" onchange="this.form.category_id.value=''; this.form.submit()" /></div>
            <div class="w-56"><x-form.select name="category_id" label="Kategori" :options="$categoryOptions" :value="$categoryId" placeholder="Semua kategori" /></div>
            <label class="mb-2 inline-flex items-center gap-2 text-sm text-gray-600">
                <input type="checkbox" name="include_zero" value="1" class="rounded border-gray-300 text-indigo-600" @checked(request()->boolean('include_zero'))>
                Tampilkan stok 0
            </label>
            <button type="submit" class="btn-secondary">Tampilkan</button>
            <div class="ml-auto self-end"><x-report.export-buttons /></div>
        </form>
        <p class="mt-3 text-xs text-gray-500">
            Nilai per {{ tanggal(today()) }} = stok × harga pokok.
            @if ($isProducts)
                Barang jadi memakai HPP rata-rata hasil produksi; bila belum pernah diproduksi memakai HPP standar BOM aktif.
            @else
                Bahan baku memakai harga pokok rata-rata tertimbang; bila belum pernah diterima memakai harga beli di master bahan.
            @endif
        </p>
    </x-card>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <p class="text-sm text-gray-500">Nilai persediaan {{ strtolower(StockValuationReport::TYPES[$type]) }}</p>
            <p class="mt-1 text-xl font-semibold text-gray-900">{{ rupiah($totals['value']) }}</p>
            <p class="mt-1 text-xs text-gray-500">{{ $totals['items'] }} item</p>
        </div>
        @if ($isProducts)
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-sm text-gray-500">Nilai jual</p>
                <p class="mt-1 text-xl font-semibold text-gray-900">{{ rupiah($totals['selling_value']) }}</p>
                <p class="mt-1 text-xs text-gray-500">Potensi margin {{ rupiah($totals['selling_value'] - $totals['value']) }}</p>
            </div>
        @endif
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <p class="text-sm text-gray-500">Memakai harga estimasi</p>
            <p @class(['mt-1 text-xl font-semibold', 'text-amber-600' => $totals['estimated'] > 0, 'text-gray-900' => ! $totals['estimated']])>{{ $totals['estimated'] }} item</p>
            <p class="mt-1 text-xs text-gray-500">{{ $isProducts ? 'Standar BOM / belum ada HPP' : 'Harga beli master' }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-4">
        <x-card title="Per Kategori" :padding="false">
            <table class="table-base">
                <tbody class="divide-y divide-gray-100">
                    @foreach ($categories as $c)
                        <tr>
                            <td>
                                <div class="flex justify-between gap-2"><span class="font-medium">{{ $c['name'] }}</span><span class="whitespace-nowrap">{{ rupiah($c['value'], false) }}</span></div>
                                <div class="mt-1 h-1.5 rounded-full bg-gray-100"><div class="h-1.5 rounded-full bg-indigo-500" style="width: {{ $c['share'] }}%"></div></div>
                                <div class="mt-0.5 text-xs text-gray-500">{{ $c['items'] }} item · {{ number_format($c['share'], 1, ',', '.') }}%</div>
                            </td>
                        </tr>
                    @endforeach
                    @if ($categories->isEmpty())
                        <x-empty-row colspan="1" />
                    @endif
                </tbody>
            </table>
        </x-card>

        <x-card title="Rincian Persediaan" :padding="false" class="xl:col-span-3">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>{{ $isProducts ? 'SKU / Produk' : 'Bahan' }}</th>
                            <th>Kategori</th>
                            <th class="text-right">Stok</th>
                            <th class="text-right">HPP / Satuan</th>
                            <th class="text-right">Nilai</th>
                            @if ($isProducts)<th class="text-right">Nilai Jual</th>@endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rows as $row)
                            <tr>
                                <td>
                                    <a href="{{ $isProducts ? route('stock.products.card', $row['key']) : route('stock.materials.card', $row['key']) }}" class="font-medium text-indigo-600 hover:underline">{{ $row['name'] }}</a>
                                    <div class="text-xs text-gray-500">{{ $row['code'] }}</div>
                                </td>
                                <td>{{ $row['category'] }}</td>
                                <td @class(['text-right whitespace-nowrap', 'text-red-600 font-medium' => $row['low_stock'] || $row['stock'] < 0])>{{ qty($row['stock']) }} <span class="text-xs text-gray-500">{{ $row['unit'] }}</span>@if (($row['defect_stock'] ?? 0) > 0)<div class="text-xs text-red-600">+ {{ qty($row['defect_stock']) }} cacat ({{ rupiah($row['defect_value'], false) }})</div>@endif</td>
                                <td class="text-right whitespace-nowrap">
                                    {{ rupiah($row['unit_cost'], false) }}
                                    <div @class(['text-xs', 'text-gray-500' => $row['basis'] === 'average', 'text-amber-600' => $row['basis'] !== 'average'])>{{ StockValuationReport::basisLabel($row['basis']) }}</div>
                                </td>
                                <td class="text-right font-semibold whitespace-nowrap">{{ rupiah($row['value'], false) }}</td>
                                @if ($isProducts)<td class="text-right whitespace-nowrap text-gray-600">{{ rupiah($row['selling_value'], false) }}</td>@endif
                            </tr>
                        @empty
                            <x-empty-row :colspan="$isProducts ? 6 : 5" message="Tidak ada persediaan." />
                        @endforelse
                    </tbody>
                    @if ($rows->isNotEmpty())
                        <tfoot class="bg-gray-50 font-semibold">
                            <tr>
                                <td class="px-4 py-3" colspan="4">Total</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($totals['value'], false) }}</td>
                                @if ($isProducts)<td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($totals['selling_value'], false) }}</td>@endif
                            </tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </x-card>
    </div>
</x-layouts.app>
