<x-layouts.app title="Dashboard">
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach (array_filter([
            ['label' => 'Penjualan bulan ini', 'value' => rupiah($stats['sales_this_month']), 'link' => route('sales-orders.index'), 'can' => 'sales.view'],
            ['label' => 'SO menunggu dikirim', 'value' => $stats['open_sales'], 'link' => route('sales-orders.index', ['status' => 'confirmed']), 'can' => 'sales.view'],
            ['label' => 'SPK dalam proses', 'value' => $stats['active_production'], 'link' => route('production-orders.index', ['status' => 'in_progress']), 'can' => 'production.view'],
            ['label' => 'PO belum diterima', 'value' => $stats['open_purchases'], 'link' => route('purchase-orders.index'), 'can' => 'purchase.view'],
            ['label' => 'Piutang jatuh tempo', 'value' => rupiah($stats['receivable_overdue']), 'link' => route('invoices.index', ['status' => 'overdue']), 'can' => 'receivables.view'],
            ['label' => 'Hutang jatuh tempo ≤ 7 hari', 'value' => rupiah($stats['payable_due']), 'link' => route('reports.payables-aging'), 'can' => 'payables.view'],
        ], fn ($card) => auth()->user()->can($card['can'])) as $card)
            <a href="{{ $card['link'] }}" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-indigo-300">
                <p class="text-sm text-gray-500">{{ $card['label'] }}</p>
                <p class="mt-2 text-2xl font-semibold text-gray-900">{{ $card['value'] }}</p>
            </a>
        @endforeach
    </div>

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
