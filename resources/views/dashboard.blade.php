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
            $grossProfit !== null ? [
                'label' => 'Laba kotor bulan ini', 'value' => rupiah($grossProfit['gross_profit']),
                'hint' => 'Margin '.$pct($grossProfit['margin']).($grossProfit['previous_margin'] !== null ? ' · bulan lalu '.$pct($grossProfit['previous_margin']) : '').' · HPP '.rupiah($grossProfit['cost']),
                'tone' => $grossProfit['gross_profit'] < 0 ? 'red' : ($grossProfit['previous_margin'] !== null && $grossProfit['dpp'] > 0 ? ($grossProfit['margin'] >= $grossProfit['previous_margin'] ? 'green' : 'amber') : null),
                'link' => route('reports.sales', ['group' => 'item']),
            ] : null,
            $pendingApprovals !== null ? [
                'label' => 'PO menunggu persetujuan', 'value' => $pendingApprovals['count'],
                'hint' => $pendingApprovals['count'] > 0 ? 'Senilai '.rupiah($pendingApprovals['total']) : 'Tidak ada PO yang perlu disetujui',
                'tone' => $pendingApprovals['count'] > 0 ? 'amber' : null,
                'link' => route('purchase-orders.index', ['status' => \App\Enums\PurchaseOrderStatus::PendingApproval->value]),
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

    {{-- Pekerjaan berikutnya sesuai hak akses: memandu alur dari pesanan sampai pembayaran --}}
    <x-card title="Perlu Ditindaklanjuti" :padding="false" class="mb-6">
        @if ($tasks->isEmpty())
            <p class="p-5 text-sm text-gray-500">Tidak ada pekerjaan yang menunggu. Semua dokumen sudah diproses.</p>
        @else
            <ul class="grid grid-cols-1 divide-y divide-gray-100 md:grid-cols-2 md:divide-y-0 xl:grid-cols-3">
                @foreach ($tasks as $task)
                    <li>
                        <a href="{{ $task['url'] }}" class="flex items-center gap-3 px-5 py-3 transition hover:bg-gray-50">
                            <span @class([
                                'flex h-9 min-w-9 shrink-0 items-center justify-center rounded-lg px-2 text-sm font-semibold tabular-nums',
                                'bg-amber-50 text-amber-700' => $task['tone'] === 'amber',
                                'bg-red-50 text-red-700' => $task['tone'] === 'red',
                                'bg-blue-50 text-blue-700' => $task['tone'] === 'blue',
                                'bg-gray-100 text-gray-700' => $task['tone'] === 'gray',
                            ])>{{ $task['count'] }}</span>
                            <span class="min-w-0">
                                <span class="block truncate text-sm font-medium text-gray-800">{{ $task['label'] }}</span>
                                <span class="block truncate text-xs text-gray-500">{{ $task['hint'] }}</span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </x-card>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($cards as $card)
            <a href="{{ $card['link'] }}" class="card group relative p-5 transition hover:border-gray-300 hover:shadow-pop">
                <div class="flex items-start justify-between gap-2">
                    <p class="text-[13px] font-medium text-gray-500">{{ $card['label'] }}</p>
                    <svg class="h-4 w-4 shrink-0 text-gray-300 transition group-hover:translate-x-0.5 group-hover:text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7 17L17 7M9 7h8v8"/></svg>
                </div>
                <p class="mt-2 text-2xl font-semibold tracking-tight text-gray-900 tabular-nums">{{ $card['value'] }}</p>
                <p @class([
                    'mt-1.5 flex items-center gap-1.5 text-xs',
                    'text-gray-500' => empty($card['tone']),
                    'text-emerald-700' => ($card['tone'] ?? null) === 'green',
                    'text-red-600' => ($card['tone'] ?? null) === 'red',
                    'text-amber-700' => ($card['tone'] ?? null) === 'amber',
                ])>
                    @unless (empty($card['tone']))<span @class(['h-1.5 w-1.5 shrink-0 rounded-full', 'bg-emerald-500' => $card['tone'] === 'green', 'bg-red-500' => $card['tone'] === 'red', 'bg-amber-500' => $card['tone'] === 'amber'])></span>@endunless
                    <span>{{ $card['hint'] }}</span>
                </p>
            </a>
        @endforeach
    </div>

    @if ($trend->isNotEmpty())
        <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
            <x-card title="Tren 6 bulan (DPP)" :class="$showSales ? 'xl:col-span-2' : 'xl:col-span-3'">
                <x-slot:header>
                    <div class="flex items-center gap-4 text-xs text-gray-600">
                        @if ($showSales)<span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-indigo-500"></span>Penjualan</span>@endif
                        @if ($showPurchases)<span class="flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-gray-300"></span>Pembelian</span>@endif
                    </div>
                </x-slot:header>
                <div class="flex items-end gap-3" role="img" aria-label="Grafik penjualan dan pembelian 6 bulan terakhir">
                    @foreach ($trend as $point)
                        <div class="flex-1">
                            <div class="flex h-48 items-end justify-center gap-1">
                                @if ($showSales)
                                    <div class="w-1/3 max-w-8 rounded-t bg-indigo-600 transition hover:bg-indigo-700" style="height: {{ max(0.5, $point['sales'] / $maxTrend * 100) }}%" title="Penjualan {{ $point['label'] }}: {{ rupiah($point['sales']) }}"></div>
                                @endif
                                @if ($showPurchases)
                                    <div class="w-1/3 max-w-8 rounded-t bg-gray-300 transition hover:bg-gray-400" style="height: {{ max(0.5, $point['purchases'] / $maxTrend * 100) }}%" title="Pembelian {{ $point['label'] }}: {{ rupiah($point['purchases']) }}"></div>
                                @endif
                            </div>
                            <div class="mt-2 border-t border-gray-200 pt-1 text-center text-xs leading-5">
                                <div class="text-gray-500">{{ $point['label'] }}</div>
                                @if ($showSales)<div class="font-medium text-indigo-600">{{ number_format($point['sales'] / 1000000, 1, ',', '.') }} jt</div>@endif
                                @if ($showPurchases)<div class="font-medium text-gray-500">{{ number_format($point['purchases'] / 1000000, 1, ',', '.') }} jt</div>@endif
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
                                        <div class="mt-0.5 text-xs text-gray-500">
                                            {{ $row['count'] }} invoice · {{ $pct($row['share']) }}
                                            @if ($withCost) · <span @class(['text-emerald-700' => $row['margin'] >= 0, 'text-red-600' => $row['margin'] < 0])>margin {{ $pct($row['margin']) }}</span>@endif
                                        </div>
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

        @if ($withCost)
        <x-card title="Produk paling menguntungkan bulan ini" :padding="false">
            <x-slot:header><a href="{{ route('reports.sales', ['group' => 'item']) }}" class="text-xs text-indigo-600 hover:underline">Laba per produk</a></x-slot:header>
            <table class="table-base">
                <thead><tr><th>Produk</th><th class="text-right">Qty</th><th class="text-right">Penjualan</th><th class="text-right">Laba Kotor</th><th class="text-right">Margin</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($topProducts as $row)
                        <tr>
                            <td><span class="font-medium">{{ $row['name'] }}</span><div class="text-xs text-gray-500">{{ $row['code'] }}</div></td>
                            <td class="text-right">{{ qty($row['quantity']) }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($row['dpp'], false) }}</td>
                            <td @class(['text-right whitespace-nowrap font-medium', 'text-emerald-700' => $row['gross_profit'] >= 0, 'text-red-600' => $row['gross_profit'] < 0])>{{ rupiah($row['gross_profit'], false) }}</td>
                            <td class="text-right">{{ $pct($row['margin']) }}</td>
                        </tr>
                    @empty
                        <x-empty-row colspan="5" message="Belum ada invoice bulan ini." />
                    @endforelse
                </tbody>
            </table>
        </x-card>
        @endif

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
