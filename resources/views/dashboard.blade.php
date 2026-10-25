<x-layouts.app title="Dashboard">
    @php
        $pct = fn ($v) => number_format((float) $v, 1, ',', '.').'%';
        $growth = $invoiced['growth'];
        $cards = array_filter([
            $receivables !== null ? [
                'label' => 'Penjualan terfaktur bulan ini', 'value' => rupiah($invoiced['current']),
                'hint' => $growth === null ? 'Bulan lalu '.rupiah($invoiced['previous']) : (($growth >= 0 ? '▲ ' : '▼ ').$pct(abs($growth)).' dibanding bulan lalu'),
                'tone' => $growth === null ? null : ($growth >= 0 ? 'green' : 'red'),
                'link' => route('reports.sales'),
            ] : null,
            auth()->user()->can('sales.view') ? [
                'label' => 'Pesanan (SO) bulan ini', 'value' => rupiah($stats['sales_this_month']),
                'hint' => $stats['open_sales'].' SO menunggu dikirim', 'link' => route('sales-orders.index'),
            ] : null,
            $receivables !== null ? [
                'label' => 'Piutang pelanggan', 'value' => rupiah($receivables['outstanding']),
                'hint' => $receivables['overdue'] > 0 ? 'Lewat jatuh tempo '.rupiah($receivables['overdue']) : $receivables['count'].' invoice belum lunas',
                'tone' => $receivables['overdue'] > 0 ? 'red' : null, 'link' => route('reports.receivables-aging'),
            ] : null,
            $payables !== null ? [
                'label' => 'Hutang supplier', 'value' => rupiah($payables['outstanding']),
                'hint' => 'Jatuh tempo ≤ 7 hari '.rupiah($stats['payable_due']),
                'tone' => $stats['payable_due'] > 0 ? 'amber' : null, 'link' => route('reports.payables-aging'),
            ] : null,
            $production !== null ? [
                'label' => 'Produksi selesai bulan ini', 'value' => qty($production['finished']).' pcs',
                'hint' => 'Reject '.$pct($production['reject_rate']).' · '.$stats['active_production'].' SPK berjalan',
                'tone' => $production['reject_rate'] > 5 ? 'red' : null, 'link' => route('reports.production'),
            ] : null,
            $stockValue !== null ? [
                'label' => 'Nilai persediaan', 'value' => rupiah($stockValue['materials'] + $stockValue['products']),
                'hint' => 'Bahan '.rupiah($stockValue['materials']).' · Jadi '.rupiah($stockValue['products']),
                'link' => route('reports.stock-valuation'),
            ] : null,
            auth()->user()->can('purchase.view') ? [
                'label' => 'PO belum diterima', 'value' => $stats['open_purchases'],
                'hint' => 'PO dipesan / diterima sebagian', 'link' => route('purchase-orders.index'),
            ] : null,
        ]);
        $maxTrend = max(1, (float) $trend->max('sales'), (float) $trend->max('purchases'));
        $showSales = auth()->user()->can('receivables.view');
        $showPurchases = auth()->user()->can('payables.view');
    @endphp

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($cards as $card)
            <a href="{{ $card['link'] }}" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-indigo-300">
                <p class="text-sm text-gray-500">{{ $card['label'] }}</p>
                <p class="mt-2 text-2xl font-semibold text-gray-900">{{ $card['value'] }}</p>
                <p @class([
                    'mt-1 text-xs',
                    'text-gray-500' => empty($card['tone']),
                    'text-emerald-600' => ($card['tone'] ?? null) === 'green',
                    'text-red-600' => ($card['tone'] ?? null) === 'red',
                    'text-amber-600' => ($card['tone'] ?? null) === 'amber',
                ])>{{ $card['hint'] }}</p>
            </a>
        @endforeach
    </div>

    @if ($trend->isNotEmpty())
        <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
            <x-card title="Tren 6 bulan (DPP)" :class="$showSales ? 'xl:col-span-2' : 'xl:col-span-3'">
                <x-slot:header>
                    <div class="flex items-center gap-4 text-xs text-gray-600">
                        @if ($showSales)<span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-indigo-500"></span>Penjualan</span>@endif
                        @if ($showPurchases)<span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-amber-400"></span>Pembelian</span>@endif
                    </div>
                </x-slot:header>
                <div class="flex items-end gap-3" role="img" aria-label="Grafik penjualan dan pembelian 6 bulan terakhir">
                    @foreach ($trend as $point)
                        <div class="flex-1">
                            <div class="flex h-48 items-end justify-center gap-1">
                                @if ($showSales)
                                    <div class="w-1/3 max-w-8 rounded-t bg-indigo-500 transition hover:bg-indigo-600" style="height: {{ max(0.5, $point['sales'] / $maxTrend * 100) }}%" title="Penjualan {{ $point['label'] }}: {{ rupiah($point['sales']) }}"></div>
                                @endif
                                @if ($showPurchases)
                                    <div class="w-1/3 max-w-8 rounded-t bg-amber-400 transition hover:bg-amber-500" style="height: {{ max(0.5, $point['purchases'] / $maxTrend * 100) }}%" title="Pembelian {{ $point['label'] }}: {{ rupiah($point['purchases']) }}"></div>
                                @endif
                            </div>
                            <div class="mt-2 border-t border-gray-200 pt-1 text-center text-xs leading-5">
                                <div class="text-gray-500">{{ $point['label'] }}</div>
                                @if ($showSales)<div class="font-medium text-indigo-600">{{ number_format($point['sales'] / 1000000, 1, ',', '.') }} jt</div>@endif
                                @if ($showPurchases)<div class="font-medium text-amber-600">{{ number_format($point['purchases'] / 1000000, 1, ',', '.') }} jt</div>@endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-card>

            @if ($showSales)
                <x-card title="Pelanggan teratas bulan ini" :padding="false">
                    <x-slot:header><a href="{{ route('reports.sales') }}" class="text-xs text-indigo-600 hover:underline">Laporan penjualan</a></x-slot:header>
                    <table class="table-base">
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($topCustomers as $row)
                                <tr>
                                    <td>
                                        <div class="flex justify-between gap-2"><span class="font-medium">{{ $row['name'] }}</span><span class="whitespace-nowrap">{{ rupiah($row['dpp'], false) }}</span></div>
                                        <div class="mt-1 h-1.5 rounded-full bg-gray-100"><div class="h-1.5 rounded-full bg-indigo-500" style="width: {{ $row['share'] }}%"></div></div>
                                        <div class="mt-0.5 text-xs text-gray-500">{{ $row['count'] }} invoice · {{ $pct($row['share']) }}</div>
                                    </td>
                                </tr>
                            @empty
                                <x-empty-row colspan="1" message="Belum ada invoice bulan ini." />
                            @endforelse
                        </tbody>
                    </table>
                </x-card>
            @endif
        </div>
    @endif

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
        @can('production.view')
        <x-card title="Produksi berjalan" :padding="false">
            <table class="table-base">
                <thead><tr><th>SPK</th><th>Produk</th><th>Target</th><th class="w-40">Progres</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($activeProduction as $po)
                        <tr>
                            <td><a href="{{ route('production-orders.show', $po) }}" class="font-medium text-indigo-600 hover:underline">{{ $po->number }}</a></td>
                            <td>{{ $po->product->name }}<div class="text-xs text-gray-500">{{ qty($po->quantity) }} pcs</div></td>
                            <td>{{ tanggal($po->due_date) }}</td>
                            <td>
                                <div class="h-2 rounded-full bg-gray-200"><div class="h-2 rounded-full bg-indigo-500" style="width: {{ $po->progressPercent() }}%"></div></div>
                                <span class="text-xs text-gray-500">{{ $po->progressPercent() }}%</span>
                            </td>
                        </tr>
                    @empty
                        <x-empty-row colspan="4" message="Tidak ada SPK yang sedang berjalan." />
                    @endforelse
                </tbody>
            </table>
        </x-card>

        @endcan

        @can('stock.view')
        <x-card title="Stok bahan menipis" :padding="false">
            <x-slot:header><a href="{{ route('stock.materials', ['low_stock' => 1]) }}" class="text-xs text-indigo-600 hover:underline">Lihat semua</a></x-slot:header>
            <table class="table-base">
                <thead><tr><th>Bahan</th><th class="text-right">Stok</th><th class="text-right">Minimum</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($lowStockMaterials as $m)
                        <tr>
                            <td><a href="{{ route('stock.materials.card', $m) }}" class="hover:underline">{{ $m->name }}</a><div class="text-xs text-gray-500">{{ $m->code }}</div></td>
                            <td class="text-right font-medium text-red-600">{{ qty($m->stock) }} {{ $m->unit->code }}</td>
                            <td class="text-right">{{ qty($m->min_stock) }}</td>
                        </tr>
                    @empty
                        <x-empty-row colspan="3" message="Semua stok bahan aman." />
                    @endforelse
                </tbody>
            </table>
        </x-card>

        @endcan

        @can('receivables.view')
        <x-card title="Invoice lewat jatuh tempo" :padding="false">
            <x-slot:header><a href="{{ route('reports.receivables-aging') }}" class="text-xs text-indigo-600 hover:underline">Umur piutang</a></x-slot:header>
            <table class="table-base">
                <thead><tr><th>Invoice</th><th>Pelanggan</th><th class="text-right">Lewat</th><th class="text-right">Sisa</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($overdueInvoices as $inv)
                        <tr>
                            <td><a href="{{ route('invoices.show', $inv) }}" class="font-medium text-indigo-600 hover:underline">{{ $inv->number }}</a></td>
                            <td>{{ $inv->customer->name }}</td>
                            <td class="text-right text-red-600">{{ $inv->daysOverdue() }} hari</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($inv->outstanding()) }}</td>
                        </tr>
                    @empty
                        <x-empty-row colspan="4" message="Tidak ada invoice yang lewat jatuh tempo." />
                    @endforelse
                </tbody>
            </table>
        </x-card>
        @endcan

        @can('payables.view')
        <x-card title="Tagihan supplier jatuh tempo 7 hari" :padding="false">
            <x-slot:header><a href="{{ route('reports.payables-aging') }}" class="text-xs text-indigo-600 hover:underline">Umur hutang</a></x-slot:header>
            <table class="table-base">
                <thead><tr><th>Tagihan</th><th>Supplier</th><th>Jatuh Tempo</th><th class="text-right">Sisa</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($upcomingBills as $bill)
                        <tr>
                            <td><a href="{{ route('bills.show', $bill) }}" class="font-medium text-indigo-600 hover:underline">{{ $bill->number }}</a></td>
                            <td>{{ $bill->supplier->name }}</td>
                            <td @class(['text-red-600' => $bill->isOverdue()])>{{ tanggal($bill->due_date) }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($bill->outstanding()) }}</td>
                        </tr>
                    @empty
                        <x-empty-row colspan="4" message="Tidak ada tagihan yang segera jatuh tempo." />
                    @endforelse
                </tbody>
            </table>
        </x-card>
        @endcan

        @can('sales.view')
        <x-card title="Sales order terbaru" :padding="false" class="xl:col-span-2">
            <table class="table-base">
                <thead><tr><th>No. SO</th><th>Pelanggan</th><th>Tanggal</th><th class="text-right">Total</th><th>Status</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($recentSales as $so)
                        <tr>
                            <td><a href="{{ route('sales-orders.show', $so) }}" class="font-medium text-indigo-600 hover:underline">{{ $so->number }}</a></td>
                            <td>{{ $so->customer->name }}</td>
                            <td>{{ tanggal($so->order_date) }}</td>
                            <td class="text-right">{{ rupiah($so->total) }}</td>
                            <td><x-status-badge :status="$so->status" /></td>
                        </tr>
                    @empty
                        <x-empty-row colspan="5" />
                    @endforelse
                </tbody>
            </table>
        </x-card>
        @endcan
    </div>
</x-layouts.app>
