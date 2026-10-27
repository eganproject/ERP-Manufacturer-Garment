<x-layouts.app title="Order Maklon">
    <x-slot:actions>
        <a href="{{ route('reports.subcontract') }}" class="btn-secondary">Saldo Bahan di Vendor</a>
        @can('subcontract.manage')
            <a href="{{ route('subcontract-orders.create') }}" class="btn-primary">+ Buat Order Maklon</a>
        @endcan
    </x-slot:actions>

    <x-summary-tiles :tiles="[
        ['Order maklon berjalan', $summary['open'].' order'],
        ['Belum kembali dari vendor', qty($summary['outstanding']).' pcs', $summary['outstanding'] > 0 ? 'amber' : null],
        ['Nilai bahan di vendor', auth()->user()->can('costing.view') ? rupiah($summary['material_value']) : '-'],
    ]" />

    <x-card :padding="false">
        <div class="border-b border-gray-200 p-4">
            <x-search-filter placeholder="Cari no. order / SPK / vendor...">
                <div class="w-56"><x-form.select name="supplier_id" :options="$vendors" :value="request('supplier_id')" placeholder="Semua vendor" data-select /></div>
                <div class="w-44"><x-form.select name="status" :options="\App\Enums\SubcontractStatus::options()" :value="request('status')" placeholder="Semua status" /></div>
            </x-search-filter>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>No. Order</th><th>Vendor</th><th>SPK / Produk</th><th>Tahap</th><th class="text-right">Qty</th><th class="text-right">Bagus / Reject</th><th>Target</th><th>Status</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($orders as $order)
                        <tr>
                            <td><a href="{{ route('subcontract-orders.show', $order) }}" class="font-medium text-indigo-600 hover:underline">{{ $order->number }}</a><div class="text-xs text-gray-500">{{ tanggal($order->order_date) }}</div></td>
                            <td>{{ $order->supplier->name }}</td>
                            <td>{{ $order->productionOrder->number }}<div class="text-xs text-gray-500">{{ $order->productionOrder->product->name }}</div></td>
                            <td class="text-xs">{{ $order->stagesLabel() }}</td>
                            <td class="text-right">{{ qty($order->quantity) }}</td>
                            <td class="text-right whitespace-nowrap"><span class="text-emerald-700">{{ qty($order->received_quantity) }}</span> / <span class="text-red-600">{{ qty($order->reject_quantity) }}</span></td>
                            <td @class(['text-red-600 font-medium' => $order->isOpen() && $order->due_date?->isPast()])>{{ tanggal($order->due_date) }}</td>
                            <td>
                                <x-status-badge :status="$order->status" />
                                @if ($order->isOpen() && $order->hasMaterialAtVendor())<div class="mt-1 text-xs text-amber-600">Bahan di vendor</div>@endif
                            </td>
                        </tr>
                    @empty
                        <x-empty-row colspan="8" message="Belum ada order maklon. Buat dari halaman SPK produksi." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-4 py-3 empty:hidden">{{ $orders->links() }}</div>
    </x-card>
</x-layouts.app>
