<x-layouts.app :title="'Invoice '.$invoice->number">
    <x-slot:actions>
        <a href="{{ route('invoices.pdf', $invoice) }}" target="_blank" class="btn-secondary">Cetak Invoice</a>
        @can('receivables.manage')
            @if ($invoice->canCancel())
                <form method="POST" action="{{ route('invoices.cancel', $invoice) }}" class="inline" data-confirm="Invoice dibatalkan dan barangnya bisa ditagihkan ulang." data-confirm-button="Ya, batalkan">
                    @csrf
                    <button type="submit" class="btn-outline-danger">Batalkan</button>
                </form>
            @endif
            @if ($invoice->isOpen())
                <a href="{{ route('receipts.create', ['document_id' => $invoice->id]) }}" class="btn-success">Catat Penerimaan</a>
            @endif
        @endcan
    </x-slot:actions>

    {{-- Petunjuk langkah berikutnya --}}
    @if ($invoice->isOpen())
        <x-next-step>Kirim invoice ke pelanggan (Cetak Invoice). Saat pelanggan membayar, klik <strong>Catat Penerimaan</strong>; sisa tagihan saat ini <strong>{{ rupiah($invoice->outstanding()) }}</strong>.</x-next-step>
    @elseif ($invoice->status === \App\Enums\InvoiceStatus::Paid)
        <x-next-step tone="green" title="Invoice lunas">Pembayaran sudah diterima penuh.</x-next-step>
    @endif

    @include('invoices.partials.document', ['doc' => $invoice, 'party' => $invoice->customer, 'source' => $invoice->salesOrder, 'sourceRoute' => 'sales-orders.show', 'sourceLabel' => 'Sales Order', 'sourceCan' => 'sales.view'])

    <x-journal-entries :sources="$invoice" class="mt-6" />
</x-layouts.app>
