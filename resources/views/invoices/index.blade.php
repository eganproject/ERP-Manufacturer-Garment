<x-layouts.app title="Invoice Penjualan">
    @can('receivables.manage')
        <x-slot:actions>
            <a href="{{ route('receipts.create') }}" class="btn-secondary">Catat Penerimaan</a>
            <a href="{{ route('invoices.create') }}" class="btn-primary">+ Buat Invoice</a>
        </x-slot:actions>
    @endcan

    <x-summary-tiles :tiles="[
        ['Total piutang berjalan', rupiah($summary['outstanding'])],
        ['Sudah jatuh tempo', rupiah($summary['overdue']), $summary['overdue'] > 0 ? 'red' : null],
        ['Invoice jatuh tempo', $summary['overdue_count'].' dokumen', $summary['overdue_count'] > 0 ? 'red' : null],
    ]" />

    <x-card :padding="false">
        <div class="border-b border-gray-100 p-4">
            <x-search-filter placeholder="Cari no. invoice / SO / pelanggan...">
                <div class="w-56"><x-form.select name="customer_id" :options="$customers" :value="request('customer_id')" placeholder="Semua pelanggan" data-select /></div>
                <div class="w-48"><x-form.select name="status" :options="['overdue' => 'Jatuh Tempo'] + \App\Enums\InvoiceStatus::options()" :value="request('status')" placeholder="Semua status" /></div>
            </x-search-filter>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>No. Invoice</th><th>Pelanggan</th><th>SO</th><th>Tanggal</th><th>Jatuh Tempo</th><th class="text-right">Total</th><th class="text-right">Sisa</th><th>Status</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($invoices as $invoice)
                        <tr>
                            <td><a href="{{ route('invoices.show', $invoice) }}" class="font-medium text-indigo-600 hover:underline">{{ $invoice->number }}</a></td>
                            <td>{{ $invoice->customer->name }}</td>
                            <td>{{ $invoice->salesOrder->number }}</td>
                            <td>{{ tanggal($invoice->invoice_date) }}</td>
                            <td @class(['text-red-600 font-medium' => $invoice->isOverdue()])>
                                {{ tanggal($invoice->due_date) }}
                                @if ($invoice->isOverdue())<div class="text-xs">lewat {{ $invoice->daysOverdue() }} hari</div>@endif
                            </td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($invoice->total) }}</td>
                            <td class="text-right whitespace-nowrap font-medium">{{ rupiah($invoice->outstanding()) }}</td>
                            <td><x-payable-status :doc="$invoice" /></td>
                        </tr>
                    @empty
                        <x-empty-row colspan="8" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-4 py-3 empty:hidden">{{ $invoices->links() }}</div>
    </x-card>
</x-layouts.app>
