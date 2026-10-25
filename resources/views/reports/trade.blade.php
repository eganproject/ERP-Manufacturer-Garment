@php
    $isSales = $kind === 'sales';
    $route = $isSales ? 'reports.sales' : 'reports.purchases';
    $partyLabel = $isSales ? 'Pelanggan' : 'Supplier';
    $docLabel = $isSales ? 'Invoice' : 'Tagihan';
    $pct = fn ($v) => number_format((float) $v, 1, ',', '.').'%';
    $withCost ??= false;
    $profitCells = function (array $row, bool $footer = false) use ($pct) {
        $pad = $footer ? 'px-4 py-3 ' : '';
        $tone = $row['gross_profit'] < 0 ? 'text-red-600' : 'text-emerald-700';

        return '<td class="'.$pad.'text-right whitespace-nowrap">'.e(rupiah($row['cost'], false)).'</td>'
            .'<td class="'.$pad.'text-right whitespace-nowrap font-semibold '.$tone.'">'.e(rupiah($row['gross_profit'], false)).'</td>'
            .'<td class="'.$pad.'text-right whitespace-nowrap '.$tone.'">'.e($pct($row['margin'])).'</td>';
    };
    $returnsHint = $totals['returns'] > 0 ? ' · setelah retur '.rupiah($totals['returns']) : '';
    $cards = $withCost ? [
        ['Penjualan (DPP)', rupiah($totals['dpp']), $totals['count'].' invoice'.$returnsHint.' · termasuk PPN '.rupiah($totals['total'])],
        ['Laba kotor', rupiah($totals['gross_profit']), 'Margin '.$pct($totals['margin']).' · HPP '.rupiah($totals['cost'])],
        ['Belum diterima', rupiah($totals['outstanding']), 'Terbayar '.rupiah($totals['paid'])],
        ['Nilai SO dikonfirmasi', rupiah($summary['orders']), 'Pesanan dibuat pada periode yang sama'],
    ] : [
        [$isSales ? 'Penjualan (DPP)' : 'Pembelian (DPP)', rupiah($totals['dpp']), $totals['count'].' '.strtolower($docLabel).$returnsHint.' · rata-rata '.rupiah($summary['average'])],
        ['Total termasuk PPN', rupiah($totals['total']), 'PPN '.rupiah($totals['tax'])],
        [$isSales ? 'Belum diterima' : 'Belum dibayar', rupiah($totals['outstanding']), 'Terbayar '.rupiah($totals['paid'])],
        [$isSales ? 'Nilai SO dikonfirmasi' : 'Nilai PO dipesan', rupiah($summary['orders']), 'Pesanan dibuat pada periode yang sama'],
    ];
@endphp
<x-layouts.app :title="$isSales ? 'Laporan Penjualan' : 'Laporan Pembelian'">
    <x-card class="mb-6">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div class="w-40"><x-form.input name="from" label="Dari Tanggal" :value="$from" data-date /></div>
            <div class="w-40"><x-form.input name="to" label="Sampai Tanggal" :value="$to" data-date /></div>
            <div class="w-64"><x-form.select name="party_id" :label="$partyLabel" :options="$parties" :value="$partyId" :placeholder="'Semua '.strtolower($partyLabel)" data-select /></div>
            <div class="w-52">
                <x-form.select name="group" label="Kelompokkan" :value="$groupBy" :placeholder="false"
                    :options="['party' => 'Per '.$partyLabel, 'item' => $isSales ? 'Per Produk' : 'Per Bahan', 'month' => 'Per Bulan']" />
            </div>
            <button type="submit" class="btn-secondary">Tampilkan</button>
            @if (request()->hasAny(['from', 'to', 'party_id', 'group']))
                <a href="{{ route($route) }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>
            @endif
        </form>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
            <x-report.period-presets />
            <x-report.export-buttons />
        </div>
        <p class="mt-2 text-xs text-gray-500">
                Dihitung dari {{ strtolower($docLabel) }} {{ $isSales ? 'penjualan' : 'supplier' }} yang tidak dibatalkan. Nilai = DPP (subtotal − diskon, sebelum PPN), dikurangi nota retur pada periode yang sama.
                @if ($withCost) HPP = harga pokok rata-rata barang saat dikirim (surat jalan); laba kotor = DPP − HPP. @endif
            </p>
    </x-card>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($cards as [$label, $value, $hint])
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-sm text-gray-500">{{ $label }}</p>
                <p class="mt-1 text-xl font-semibold text-gray-900">{{ $value }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
            </div>
        @endforeach
    </div>

    <x-card :title="($isSales ? 'Penjualan' : 'Pembelian').' per '.match ($groupBy) { 'item' => $isSales ? 'produk' : 'bahan', 'month' => 'bulan', default => strtolower($partyLabel) }" :padding="false">
        <x-slot:header><span class="text-xs text-gray-500">{{ tanggal($from) }} s/d {{ tanggal($to) }}</span></x-slot:header>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead>
                    <tr>
                        @if ($groupBy === 'item')
                            <th>{{ $isSales ? 'Produk' : 'Bahan' }}</th>
                            <th class="text-right">{{ $docLabel }}</th>
                            <th class="text-right">Qty</th>
                            <th class="text-right">Harga Rata-rata</th>
                            <th class="text-right">Nilai (DPP)</th>
                            @if ($withCost)<th class="text-right">HPP</th><th class="text-right">Laba Kotor</th><th class="text-right">Margin</th>@endif
                        @else
                            <th>{{ $groupBy === 'month' ? 'Bulan' : $partyLabel }}</th>
                            <th class="text-right">{{ $docLabel }}</th>
                            @if ($isSales)<th class="text-right">Qty (pcs)</th>@endif
                            <th class="text-right">Nilai (DPP)</th>
                            @if ($withCost)<th class="text-right">HPP</th><th class="text-right">Laba Kotor</th><th class="text-right">Margin</th>@endif
                            <th class="text-right">PPN</th>
                            <th class="text-right">Total</th>
                            <th class="text-right">Sisa</th>
                        @endif
                        <th class="w-44">Kontribusi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($rows as $row)
                        <tr>
                            <td>
                                @if ($groupBy === 'party')
                                    <a href="{{ request()->fullUrlWithQuery(['party_id' => $row['key'], 'group' => 'item']) }}" class="font-medium text-indigo-600 hover:underline">{{ $row['name'] }}</a>
                                    <div class="text-xs text-gray-500">{{ $row['code'] }}</div>
                                @elseif ($groupBy === 'item')
                                    <span class="font-medium">{{ $row['name'] }}</span>
                                    <div class="text-xs text-gray-500">{{ $row['code'] }}</div>
                                @else
                                    <span class="font-medium">{{ $row['name'] }}</span>
                                @endif
                            </td>
                            <td class="text-right">{{ $row['count'] }}</td>
                            @if ($groupBy === 'item')
                                <td class="text-right whitespace-nowrap">{{ qty($row['quantity']) }} <span class="text-xs text-gray-500">{{ $row['unit'] }}</span></td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($row['average_price'], false) }}</td>
                                <td class="text-right font-semibold whitespace-nowrap">{{ rupiah($row['dpp'], false) }}</td>
                                @if ($withCost){!! $profitCells($row) !!}@endif
                            @else
                                @if ($isSales)<td class="text-right whitespace-nowrap">{{ qty($row['quantity']) }}</td>@endif
                                <td class="text-right font-semibold whitespace-nowrap">{{ rupiah($row['dpp'], false) }}</td>
                                @if ($withCost){!! $profitCells($row) !!}@endif
                                <td class="text-right whitespace-nowrap">{{ rupiah($row['tax'], false) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($row['total'], false) }}</td>
                                <td @class(['text-right whitespace-nowrap', 'text-amber-600' => $row['outstanding'] > 0, 'text-gray-300' => $row['outstanding'] <= 0])>{{ rupiah($row['outstanding'], false) }}</td>
                            @endif
                            <td>
                                <div class="flex items-center gap-2">
                                    <div class="h-2 flex-1 rounded-full bg-gray-100"><div class="h-2 rounded-full bg-indigo-500" style="width: {{ min(100, $row['share']) }}%"></div></div>
                                    <span class="w-12 text-right text-xs text-gray-600">{{ $pct($row['share']) }}</span>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="$withCost ? 11 : 8" :message="'Tidak ada '.strtolower($docLabel).' pada periode ini.'" />
                    @endforelse
                </tbody>
                @if ($rows->isNotEmpty())
                    <tfoot class="bg-gray-50 font-semibold">
                        <tr>
                            <td class="px-4 py-3">Total</td>
                            <td class="px-4 py-3 text-right">{{ $totals['count'] }}</td>
                            @if ($groupBy === 'item')
                                <td class="px-4 py-3 text-right">{{ $isSales ? qty($totals['quantity']) : '' }}</td>
                                <td></td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($totals['dpp'], false) }}</td>
                                @if ($withCost){!! $profitCells($totals, true) !!}@endif
                            @else
                                @if ($isSales)<td class="px-4 py-3 text-right">{{ qty($totals['quantity']) }}</td>@endif
                                <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($totals['dpp'], false) }}</td>
                                @if ($withCost){!! $profitCells($totals, true) !!}@endif
                                <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($totals['tax'], false) }}</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($totals['total'], false) }}</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($totals['outstanding'], false) }}</td>
                            @endif
                            <td></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </x-card>
</x-layouts.app>
