@use('App\Services\Reports\MarketplaceReport')
@php
    $pct = fn ($v) => number_format((float) $v, 1, ',', '.').'%';
    $t = $totals;
@endphp
<x-layouts.app title="Laporan Penjualan Marketplace">
    <x-card class="mb-6">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div class="w-48"><x-form.select name="view" label="Tampilan" :options="MarketplaceReport::VIEWS" :value="$view" :placeholder="false" onchange="this.form.submit()" /></div>
            <div class="w-40"><x-form.input name="from" label="Dikirim Dari" :value="$from" data-date /></div>
            <div class="w-40"><x-form.input name="to" label="Sampai" :value="$to" data-date /></div>
            <div class="w-40"><x-form.select name="platform" label="Platform" :options="$platforms" :value="$platform" placeholder="Semua" /></div>
            <div class="w-60"><x-form.select name="store_id" label="Toko" :options="$stores" :value="$storeId" placeholder="Semua toko" /></div>
            <button type="submit" class="btn-secondary">Tampilkan</button>
        </form>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
            <x-report.period-presets />
            @if ($withCost)<x-report.export-buttons />@endif
        </div>
        <p class="mt-2 text-xs text-gray-500">
            Pesanan yang dikirim pada periode, bersih retur, nilai tanpa PPN. Voucher, biaya platform (komisi, admin, layanan, transaksi, afiliasi) dan ongkir yang ditanggung penjual diambil dari pencairan dana yang sudah diposting;
            pesanan yang belum cair belum memuat potongan ini. Margin = penjualan bersih − potongan platform − selisih pencairan − HPP.
        </p>
    </x-card>

    @if ($view === 'products')
        <x-summary-tiles :tiles="[
            ['Qty terjual bersih', qty($t['qty']).' pcs'],
            ['Penjualan bersih (DPP)', rupiah($t['sales'])],
            ['Laba kotor', $withCost ? rupiah($t['gross_profit']).' ('.$pct($t['margin_pct']).')' : '-'],
        ]" />
    @else
        <x-summary-tiles :tiles="[
            ['Penjualan bersih (DPP) · '.$t['orders'].' pesanan', rupiah($t['sales'])],
            ['Potongan platform', rupiah($t['voucher'] + $t['fees'] + $t['shipping']).' ('.$pct($t['fee_pct']).')'],
            ['Margin', $withCost ? rupiah($t['margin']).' ('.$pct($t['margin_pct']).')' : '-'],
        ]" />
        @if ($t['unsettled'])
            <p class="mb-4 text-sm text-amber-600">{{ $t['unsettled'] }} pesanan belum cair (piutang marketplace {{ rupiah($t['outstanding']) }}); potongan platformnya belum masuk margin.</p>
        @endif
    @endif

    <x-card :padding="false">
        <div class="overflow-x-auto">
            <table class="table-base">
                @if ($view === 'products')
                    <thead><tr><th>SKU</th><th>Produk</th><th class="text-right">Qty Bersih</th><th class="text-right">Penjualan (DPP)</th>@if ($withCost)<th class="text-right">HPP</th><th class="text-right">Laba Kotor</th><th class="text-right">Margin</th>@endif</tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rows as $row)
                            <tr>
                                <td class="font-medium">{{ $row['sku'] }}</td><td>{{ $row['product'] }}</td>
                                <td class="text-right">{{ qty($row['qty']) }}</td><td class="text-right whitespace-nowrap">{{ rupiah($row['sales']) }}</td>
                                @if ($withCost)<td class="text-right whitespace-nowrap">{{ rupiah($row['cost']) }}</td><td class="text-right whitespace-nowrap">{{ rupiah($row['gross_profit']) }}</td><td class="text-right">{{ $pct($row['margin_pct']) }}</td>@endif
                            </tr>
                        @empty
                            <x-empty-row colspan="7" message="Tidak ada pesanan dikirim pada periode ini." />
                        @endforelse
                    </tbody>
                @else
                    <thead><tr>
                        @if ($view === 'orders')<th>Pesanan</th>@else<th>Platform / Toko</th><th class="text-right">Pesanan</th>@endif
                        <th class="text-right">Penjualan</th><th class="text-right">Retur</th><th class="text-right">Bersih</th><th class="text-right">Voucher</th><th class="text-right">Biaya</th><th class="text-right">Ongkir</th><th class="text-right">Selisih</th>
                        @if ($withCost)<th class="text-right">HPP</th><th class="text-right">Margin</th>@endif<th class="text-right">Dana Cair</th>
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rows as $row)
                            <tr>
                                @if ($view === 'orders')
                                    <td class="whitespace-nowrap"><a href="{{ route('marketplace-orders.show', $row['order_id']) }}" class="font-medium text-indigo-600 hover:underline">{{ $row['order_number'] }}</a><div class="text-xs text-gray-500">{{ tanggal($row['date']) }} · {{ $row['platform'] }} {{ $row['store'] }} · {{ $row['status'] }}</div></td>
                                @else
                                    <td><span class="font-medium">{{ $row['platform'] }}</span><div class="text-xs text-gray-500">{{ $row['store'] }}</div></td>
                                    <td class="text-right">{{ $row['orders'] }}</td>
                                @endif
                                <td class="text-right whitespace-nowrap">{{ rupiah($row['gross'], false) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($row['returns'], false) }}</td>
                                <td class="text-right font-medium whitespace-nowrap">{{ rupiah($row['sales'], false) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($row['voucher'], false) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($row['fees'], false) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($row['shipping'], false) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($row['adjustment'], false) }}</td>
                                @if ($withCost)
                                    <td class="text-right whitespace-nowrap">{{ rupiah($row['cost'], false) }}</td>
                                    <td @class(['text-right font-semibold whitespace-nowrap', 'text-red-600' => $row['margin'] < 0])>{{ rupiah($row['margin'], false) }}<div class="text-xs font-normal text-gray-500">{{ $pct($row['margin_pct']) }}</div></td>
                                @endif
                                <td class="text-right whitespace-nowrap">{{ rupiah($row['net_received'], false) }}</td>
                            </tr>
                        @empty
                            <x-empty-row colspan="13" message="Tidak ada pesanan dikirim pada periode ini." />
                        @endforelse
                    </tbody>
                    @if ($rows->isNotEmpty())
                        <tfoot class="bg-gray-50 font-semibold">
                            <tr>
                                @if ($view === 'orders')<td>Total</td>@else<td>Total</td><td class="text-right">{{ $t['orders'] }}</td>@endif
                                <td class="text-right whitespace-nowrap">{{ rupiah($t['gross'], false) }}</td><td class="text-right whitespace-nowrap">{{ rupiah($t['returns'], false) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($t['sales'], false) }}</td><td class="text-right whitespace-nowrap">{{ rupiah($t['voucher'], false) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($t['fees'], false) }}</td><td class="text-right whitespace-nowrap">{{ rupiah($t['shipping'], false) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($t['adjustment'], false) }}</td>
                                @if ($withCost)<td class="text-right whitespace-nowrap">{{ rupiah($t['cost'], false) }}</td><td class="text-right whitespace-nowrap">{{ rupiah($t['margin'], false) }}</td>@endif
                                <td class="text-right whitespace-nowrap">{{ rupiah($t['net_received'], false) }}</td>
                            </tr>
                        </tfoot>
                    @endif
                @endif
            </table>
        </div>
    </x-card>
</x-layouts.app>
