@use('App\Enums\MarketplaceOrderStatus')
<x-layouts.app title="Pesanan Marketplace">
    <x-slot:actions>
        <a href="{{ route('marketplace-orders.picking', array_filter(['store_id' => request('store_id')])) }}" target="_blank" class="btn-secondary">Daftar Ambil Barang</a>
        <a href="{{ route('marketplace-orders.template') }}" class="btn-secondary">Template Excel</a>
        @can('marketplace.manage')<a href="{{ route('marketplace-orders.import') }}" class="btn-primary">Impor Pesanan</a>@endcan
    </x-slot:actions>

    @if (session('import_issues'))
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            <p class="font-medium">Perlu perhatian ({{ count(session('import_issues')) }}):</p>
            <ul class="mt-1 list-disc pl-5">@foreach (session('import_issues') as $issue)<li>{{ $issue }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="mb-4 flex flex-wrap gap-2 text-sm">
        @php $base = request()->except(['status', 'page']); @endphp
        <a href="{{ route('marketplace-orders.index', $base) }}" @class(['rounded-full px-3 py-1 border font-medium transition', 'bg-gray-900 text-white border-gray-900' => ! request('status'), 'bg-white border-gray-200 text-gray-600 hover:border-gray-300 hover:text-gray-900' => request('status')])>Semua</a>
        @foreach (MarketplaceOrderStatus::cases() as $status)
            <a href="{{ route('marketplace-orders.index', $base + ['status' => $status->value]) }}" @class(['rounded-full px-3 py-1 border font-medium transition', 'bg-gray-900 text-white border-gray-900' => request('status') === $status->value, 'bg-white border-gray-200 text-gray-600 hover:border-gray-300 hover:text-gray-900' => request('status') !== $status->value])>
                {{ $status->label() }} <span class="opacity-70">{{ $counts[$status->value] ?? 0 }}</span>
            </a>
        @endforeach
        @php $states = collect($allocation)->countBy('state'); @endphp
        @foreach (['ready' => ['Siap Dikirim', $states['ready'] ?? 0], 'waiting' => ['Menunggu Produksi', $states['waiting'] ?? 0], 'preorder' => ['PO belum dikirim', null]] as $key => [$label, $n])
            <a href="{{ route('marketplace-orders.index', $base + ['status' => $key]) }}" @class(['rounded-full px-3 py-1 border font-medium transition', 'bg-gray-900 text-white border-gray-900' => request('status') === $key, 'bg-white border-gray-200 text-gray-600 hover:border-gray-300 hover:text-gray-900' => request('status') !== $key])>{{ $label }}@if ($n !== null) <span class="opacity-70">{{ $n }}</span>@endif</a>
        @endforeach
        <a href="{{ route('marketplace-orders.index', $base + ['status' => 'unmapped']) }}" @class(['rounded-full px-3 py-1 border font-medium transition', 'bg-gray-900 text-white border-gray-900' => request('status') === 'unmapped', 'bg-white border-gray-200 text-gray-600 hover:border-gray-300 hover:text-gray-900' => request('status') !== 'unmapped'])>SKU belum dipetakan</a>
        @foreach (['claimable' => 'Retur bermasalah', 'claim_open' => 'Banding diproses'] as $key => $label)
            <a href="{{ route('marketplace-orders.index', $base + ['status' => $key]) }}" @class(['rounded-full px-3 py-1 border font-medium transition', 'bg-gray-900 text-white border-gray-900' => request('status') === $key, 'bg-white border-gray-200 text-gray-600 hover:border-gray-300 hover:text-gray-900' => request('status') !== $key])>{{ $label }}</a>
        @endforeach
        <a href="{{ route('marketplace-orders.index', $base + ['status' => 'unsettled']) }}" @class(['rounded-full px-3 py-1 border font-medium transition', 'bg-gray-900 text-white border-gray-900' => request('status') === 'unsettled', 'bg-white border-gray-200 text-gray-600 hover:border-gray-300 hover:text-gray-900' => request('status') !== 'unsettled'])>Belum cair</a>
    </div>

    <form method="POST" action="{{ route('marketplace-orders.ship-many') }}" id="ship-many" data-confirm="Pesanan yang dipilih dikirim: stok keluar dari gudang toko & penjualan dicatat." data-confirm-button="Ya, kirim">@csrf</form>

    <x-card :padding="false">
        <div class="border-b border-gray-100 p-4">
            <x-search-filter placeholder="No. pesanan / pembeli / resi...">
                @if (request('status'))<input type="hidden" name="status" value="{{ request('status') }}">@endif
                <div class="w-64"><x-form.select name="store_id" :options="$stores" :value="request('store_id')" placeholder="Semua toko" /></div>
                <div class="w-36"><x-form.input name="from" :value="request('from')" data-date placeholder="Dari" /></div>
                <div class="w-36"><x-form.input name="to" :value="request('to')" data-date placeholder="Sampai" /></div>
            </x-search-filter>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr>
                    @can('marketplace.manage')<th class="w-8"></th>@endcan
                    <th>No. Pesanan</th><th>Tanggal</th><th>Toko</th><th>Pembeli</th><th>Barang</th><th class="text-right">Total</th><th>Status</th><th>Kirim / Cair</th>
                </tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($orders as $order)
                        <tr>
                            @can('marketplace.manage')
                                <td>@if ($order->status === MarketplaceOrderStatus::ToShip && ! $order->hasUnmappedItems())<input type="checkbox" name="orders[]" value="{{ $order->id }}" form="ship-many" class="rounded border-gray-300">@endif</td>
                            @endcan
                            <td><a href="{{ route('marketplace-orders.show', $order) }}" class="font-medium text-indigo-600 hover:underline">{{ $order->order_number }}</a>
                                @if ($order->tracking_number)<div class="text-xs text-gray-500">{{ $order->courier }} {{ $order->tracking_number }}</div>@endif</td>
                            <td class="whitespace-nowrap">{{ tanggal($order->order_date) }}</td>
                            <td class="whitespace-nowrap"><x-badge :color="$order->store->platform->color()">{{ $order->store->platform->label() }}</x-badge><div class="text-xs text-gray-500">{{ $order->store->name }}</div></td>
                            <td>{{ $order->buyer_username ?? $order->recipient_name ?? '-' }}@if ($order->city)<div class="text-xs text-gray-500">{{ $order->city }}</div>@endif</td>
                            <td class="whitespace-nowrap">
                                {{ $order->items->count() }} SKU · {{ qty($order->items->sum('quantity')) }} pcs
                                @if ($order->hasUnmappedItems() && ! $order->isShipped())<div class="text-xs text-red-600">SKU belum dipetakan</div>@endif
                            </td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($order->total) }}</td>
                            <td><x-status-badge :status="$order->status" />
                                @if (! $order->isShipped() && $order->status !== MarketplaceOrderStatus::Cancelled)
                                    <div class="mt-1 flex flex-wrap items-center gap-1 text-xs whitespace-nowrap">
                                        @if ($order->is_preorder)<x-badge color="indigo">PO</x-badge>@endif
                                        @if ($order->ship_by)<span @class(['text-gray-500', 'font-medium text-red-600' => $order->daysToShipBy() <= \App\Services\Marketplace\MarketplaceFulfillment::URGENT_DAYS])>kirim ≤ {{ tanggal($order->ship_by) }}</span>@endif
                                    </div>
                                    @php $st = $allocation[$order->id]['state'] ?? null; @endphp
                                    @if ($st === 'ready')<div class="mt-1"><x-badge color="green">Siap dikirim</x-badge></div>
                                    @elseif ($st === 'waiting')<div class="mt-1"><x-badge color="amber">Menunggu produksi</x-badge></div>@endif
                                @endif
                                @if ($order->return_condition && $order->return_condition->claimable())<div class="mt-1"><x-badge :color="$order->return_condition->color()">{{ $order->return_condition->label() }}</x-badge></div>@endif
                                @if ($order->claim_status)<div class="mt-1"><x-status-badge :status="$order->claim_status" /></div>@endif</td>
                            <td class="text-sm whitespace-nowrap">
                                {{ $order->shipped_at ? tanggal($order->shipped_at) : '-' }}
                                @if ($order->outstandingReceivable() > 0)
                                    <div class="text-xs text-amber-600">Belum cair</div>
                                @elseif ((float) $order->settled_net)
                                    <div class="text-xs text-green-700">Cair {{ rupiah($order->settled_net) }}</div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-empty-row colspan="9" message="Belum ada pesanan. Impor file ekspor pesanan dari Seller Center." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-2 p-4">
            @can('marketplace.manage')
                <div class="flex items-center gap-2">
                    <input type="text" name="date" form="ship-many" value="{{ today()->toDateString() }}" data-date class="form-control w-36">
                    <button type="submit" form="ship-many" class="btn-secondary">Kirim yang Dipilih</button>
                </div>
            @endcan
            {{ $orders->links() }}
        </div>
    </x-card>
</x-layouts.app>
