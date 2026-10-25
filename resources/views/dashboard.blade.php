<x-layouts.app title="Dashboard">
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ([
            ['label' => 'Penjualan bulan ini', 'value' => rupiah($stats['sales_this_month']), 'link' => route('sales-orders.index')],
            ['label' => 'SO menunggu dikirim', 'value' => $stats['open_sales'], 'link' => route('sales-orders.index', ['status' => 'confirmed'])],
            ['label' => 'SPK dalam proses', 'value' => $stats['active_production'], 'link' => route('production-orders.index', ['status' => 'in_progress'])],
            ['label' => 'PO belum diterima', 'value' => $stats['open_purchases'], 'link' => route('purchase-orders.index')],
        ] as $card)
            <a href="{{ $card['link'] }}" class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm transition hover:border-indigo-300">
                <p class="text-sm text-gray-500">{{ $card['label'] }}</p>
                <p class="mt-2 text-2xl font-semibold text-gray-900">{{ $card['value'] }}</p>
            </a>
        @endforeach
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 xl:grid-cols-2">
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
    </div>
</x-layouts.app>
