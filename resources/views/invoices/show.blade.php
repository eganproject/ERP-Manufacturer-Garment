<x-layouts.app :title="'Invoice '.$invoice->number">
    <x-slot:actions>
        <a href="{{ route('invoices.pdf', $invoice) }}" target="_blank" class="btn-secondary">Cetak Invoice</a>
        @can('receivables.manage')
            @if ($invoice->canCancel())
                <form method="POST" action="{{ route('invoices.cancel', $invoice) }}" class="inline" data-confirm="Invoice dibatalkan dan barangnya bisa ditagihkan ulang." data-confirm-button="Ya, batalkan">
                    @csrf
                    <button type="submit" class="btn-secondary !text-red-600">Batalkan</button>
                </form>
            @endif
            @if ($invoice->isOpen())
                <a href="{{ route('receipts.create', ['document_id' => $invoice->id]) }}" class="btn-success">Catat Penerimaan</a>
            @endif
        @endcan
    </x-slot:actions>

    @include('invoices.partials.document', ['doc' => $invoice, 'party' => $invoice->customer, 'source' => $invoice->salesOrder, 'sourceRoute' => 'sales-orders.show', 'sourceLabel' => 'Sales Order', 'sourceCan' => 'sales.view'])

    <x-journal-entries :sources="$invoice" class="mt-6" />
</x-layouts.app>
