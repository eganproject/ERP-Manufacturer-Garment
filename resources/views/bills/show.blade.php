<x-layouts.app :title="'Tagihan '.$bill->number">
    <x-slot:actions>
        <a href="{{ route('bills.pdf', $bill) }}" target="_blank" class="btn-secondary">Cetak</a>
        @can('payables.manage')
            @if ($bill->canCancel() && ! $bill->fixedAsset)
                <form method="POST" action="{{ route('bills.cancel', $bill) }}" class="inline" data-confirm="Tagihan dibatalkan dan barangnya bisa ditagihkan ulang." data-confirm-button="Ya, batalkan">
                    @csrf
                    <button type="submit" class="btn-outline-danger">Batalkan</button>
                </form>
            @endif
            @if ($bill->isOpen())
                <a href="{{ route('disbursements.create', ['document_id' => $bill->id]) }}" class="btn-success">Bayar Tagihan</a>
            @endif
        @endcan
    </x-slot:actions>

    {{-- Petunjuk langkah berikutnya --}}
    @if ($bill->isOpen())
        <x-next-step>Bayar sebelum jatuh tempo{{ $bill->due_date ? ' ('.tanggal($bill->due_date).')' : '' }} lewat <strong>Bayar Tagihan</strong>; sisa tagihan saat ini <strong>{{ rupiah($bill->outstanding()) }}</strong>.</x-next-step>
    @elseif ($bill->status === \App\Enums\InvoiceStatus::Paid)
        <x-next-step tone="green" title="Tagihan lunas">Tagihan supplier ini sudah dibayar penuh.</x-next-step>
    @endif

    @if ($bill->subcontractOrder)
        @include('invoices.partials.document', ['doc' => $bill, 'party' => $bill->supplier, 'source' => $bill->subcontractOrder, 'sourceRoute' => 'subcontract-orders.show', 'sourceLabel' => 'Order Maklon', 'sourceCan' => 'production.view'])
    @elseif ($bill->fixedAsset)
        @include('invoices.partials.document', ['doc' => $bill, 'party' => $bill->supplier, 'source' => $bill->fixedAsset, 'sourceRoute' => 'fixed-assets.show', 'sourceLabel' => 'Aset Tetap', 'sourceCan' => 'assets.view'])
    @else
        @include('invoices.partials.document', ['doc' => $bill, 'party' => $bill->supplier, 'source' => $bill->purchaseOrder, 'sourceRoute' => 'purchase-orders.show', 'sourceLabel' => 'Purchase Order', 'sourceCan' => 'purchase.view'])
    @endif

    <x-journal-entries :sources="$bill" class="mt-6" />
</x-layouts.app>
