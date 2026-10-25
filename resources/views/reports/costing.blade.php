@php
    $pct = fn ($v) => $v === null ? '-' : number_format($v, 1, ',', '.').'%';
@endphp
<x-layouts.app title="HPP & Margin Produk">
    <x-card class="mb-6">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div class="w-44"><x-form.input name="from" label="Dari Tanggal" :value="$from" data-date /></div>
            <div class="w-44"><x-form.input name="to" label="Sampai Tanggal" :value="$to" data-date /></div>
            <div class="w-72"><x-form.select name="product_id" label="Produk" :options="$productOptions" :value="$productId" placeholder="Semua produk" data-select /></div>
            <button type="submit" class="btn-secondary">Tampilkan</button>
            @if (request()->hasAny(['from', 'to', 'product_id']))
                <a href="{{ route('reports.costing') }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>
            @endif
        </form>
        <p class="mt-3 text-xs text-gray-500">
            HPP aktual = bahan yang dikeluarkan (harga pokok rata-rata) + upah/CMT/overhead yang dicatat di SPK, dibagi jumlah barang jadi. HPP standar dihitung dari BOM aktif dengan harga pokok bahan saat ini. Harga jual memakai rata-rata SO pada periode ini, atau harga master produk bila belum ada penjualan.
        </p>
    </x-card>

    <x-summary-tiles :tiles="[
        ['SPK selesai pada periode', $totals['spk'].' SPK · '.qty($totals['finished']).' pcs'],
        ['Total biaya produksi', rupiah($totals['total_cost'])],
        ['HPP rata-rata per pcs', rupiah($totals['average_unit'])],
    ]" />

    <x-card title="Margin per Artikel" :padding="false" class="mb-6">
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead>
                    <tr>
                        <th>Produk</th>
                        <th class="text-right">Diproduksi</th>
                        <th class="text-right">Bahan / pcs</th>
                        <th class="text-right">Konversi / pcs</th>
                        <th class="text-right">HPP Aktual</th>
                        <th class="text-right">HPP Standar</th>
                        <th class="text-right">Selisih</th>
                        <th class="text-right">Harga Jual</th>
                        <th class="text-right">Margin / pcs</th>
                        <th class="text-right">Margin %</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($products as $row)
                        <tr>
                            <td>
                                <span class="font-medium">{{ $row['product']->code }}</span> - {{ $row['product']->name }}
                                @if ($row['product']->activeBom)
                                    <div class="text-xs">@can('boms.manage')<a href="{{ route('boms.show', $row['product']->activeBom) }}" class="text-indigo-600 hover:underline">{{ $row['product']->activeBom->name }}</a>@else{{ $row['product']->activeBom->name }}@endcan</div>
                                @endif
                            </td>
                            <td class="text-right whitespace-nowrap">{{ qty($row['finished']) }} <span class="text-xs text-gray-500">({{ $row['spk_count'] }} SPK)</span></td>
                            <td class="text-right whitespace-nowrap">{{ $row['material_unit'] !== null ? rupiah($row['material_unit'], false) : '-' }}</td>
                            <td class="text-right whitespace-nowrap">{{ $row['conversion_unit'] !== null ? rupiah($row['conversion_unit'], false) : '-' }}</td>
                            <td class="text-right font-semibold whitespace-nowrap">{{ $row['actual_unit'] !== null ? rupiah($row['actual_unit'], false) : '-' }}</td>
                            <td class="text-right whitespace-nowrap">{{ $row['standard_unit'] !== null ? rupiah($row['standard_unit'], false) : '-' }}</td>
                            <td @class(['text-right whitespace-nowrap', 'text-red-600' => ($row['variance'] ?? 0) > 0, 'text-emerald-600' => ($row['variance'] ?? 0) < 0])>
                                {{ $row['variance'] !== null ? ($row['variance'] > 0 ? '+' : '').rupiah($row['variance'], false) : '-' }}
                            </td>
                            <td class="text-right whitespace-nowrap">
                                {{ rupiah($row['selling_price'], false) }}
                                <div class="text-xs text-gray-500">{{ $row['price_source'] === 'so' ? 'rata-rata SO' : 'harga master' }}</div>
                            </td>
                            <td @class(['text-right font-semibold whitespace-nowrap', 'text-red-600' => ($row['margin'] ?? 0) < 0, 'text-emerald-700' => ($row['margin'] ?? 0) > 0])>
                                {{ $row['margin'] !== null ? rupiah($row['margin'], false) : '-' }}
                                @if ($row['actual_unit'] === null && $row['margin'] !== null)<div class="text-xs font-normal text-gray-500">dari HPP standar</div>@endif
                            </td>
                            <td @class(['text-right whitespace-nowrap', 'text-red-600' => ($row['margin_percent'] ?? 0) < 0])>{{ $pct($row['margin_percent']) }}</td>
                        </tr>
                    @empty
                        <x-empty-row colspan="10" message="Belum ada produk dengan BOM atau SPK selesai." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <x-card title="Rincian HPP per SPK" :padding="false">
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>No. SPK</th><th>Produk</th><th>Selesai</th><th class="text-right">Jadi / Reject</th><th class="text-right">Biaya Bahan</th><th class="text-right">Konversi</th><th class="text-right">Total</th><th class="text-right">HPP / pcs</th><th class="text-right">Standar / pcs</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($orders as $order)
                        @php $standard = $order->bom->standardUnitCost(); @endphp
                        <tr>
                            <td>@can('production.view')<a href="{{ route('production-orders.show', $order) }}" class="font-medium text-indigo-600 hover:underline">{{ $order->number }}</a>@else<span class="font-medium">{{ $order->number }}</span>@endcan</td>
                            <td>{{ $order->product->name }}</td>
                            <td class="whitespace-nowrap">{{ tanggal($order->completed_at) }}</td>
                            <td class="text-right whitespace-nowrap">{{ qty($order->finished_quantity) }} / <span class="text-red-600">{{ qty($order->reject_quantity) }}</span></td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($order->material_cost, false) }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($order->conversion_cost, false) }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($order->total_cost, false) }}</td>
                            <td @class(['text-right font-semibold whitespace-nowrap', 'text-red-600' => (float) $order->unit_cost > $standard + 0.5])>{{ rupiah($order->unit_cost, false) }}</td>
                            <td class="text-right whitespace-nowrap text-gray-500">{{ rupiah($standard, false) }}</td>
                        </tr>
                    @empty
                        <x-empty-row colspan="9" message="Belum ada SPK yang selesai pada periode ini." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</x-layouts.app>
