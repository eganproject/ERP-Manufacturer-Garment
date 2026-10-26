<x-layouts.app title="Tagihan Supplier">
    @can('payables.manage')
        <x-slot:actions>
            <a href="{{ route('disbursements.create') }}" class="btn-secondary">Bayar Supplier</a>
            <a href="{{ route('bills.create') }}" class="btn-primary">+ Catat Tagihan</a>
        </x-slot:actions>
    @endcan

    <x-summary-tiles :tiles="[
        ['Total hutang berjalan', rupiah($summary['outstanding'])],
        ['Sudah jatuh tempo', rupiah($summary['overdue']), $summary['overdue'] > 0 ? 'red' : null],
        ['Jatuh tempo 7 hari ke depan', rupiah($summary['due_soon']), $summary['due_soon'] > 0 ? 'amber' : null],
    ]" />

    <x-card :padding="false">
        <div class="border-b border-gray-200 p-4">
            <x-search-filter placeholder="Cari no. tagihan / faktur / PO / maklon / supplier...">
                <div class="w-56"><x-form.select name="supplier_id" :options="$suppliers" :value="request('supplier_id')" placeholder="Semua supplier" data-select /></div>
                <div class="w-48"><x-form.select name="status" :options="['overdue' => 'Jatuh Tempo'] + \App\Enums\InvoiceStatus::options()" :value="request('status')" placeholder="Semua status" /></div>
            </x-search-filter>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>No. Tagihan</th><th>Supplier</th><th>PO / Maklon</th><th>Tanggal</th><th>Jatuh Tempo</th><th class="text-right">Total</th><th class="text-right">Sisa</th><th>Status</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($bills as $bill)
                        <tr>
                            <td>
                                <a href="{{ route('bills.show', $bill) }}" class="font-medium text-indigo-600 hover:underline">{{ $bill->number }}</a>
                                @if ($bill->supplier_invoice_number)<div class="text-xs text-gray-500">Faktur {{ $bill->supplier_invoice_number }}</div>@endif
                            </td>
                            <td>{{ $bill->supplier->name }}</td>
                            <td>{{ $bill->source()?->number ?? '-' }}</td>
                            <td>{{ tanggal($bill->bill_date) }}</td>
                            <td @class(['text-red-600 font-medium' => $bill->isOverdue()])>
                                {{ tanggal($bill->due_date) }}
                                @if ($bill->isOverdue())<div class="text-xs">lewat {{ $bill->daysOverdue() }} hari</div>@endif
                            </td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($bill->total) }}</td>
                            <td class="text-right whitespace-nowrap font-medium">{{ rupiah($bill->outstanding()) }}</td>
                            <td><x-payable-status :doc="$bill" /></td>
                        </tr>
                    @empty
                        <x-empty-row colspan="8" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-4 py-3 empty:hidden">{{ $bills->links() }}</div>
    </x-card>
</x-layouts.app>
