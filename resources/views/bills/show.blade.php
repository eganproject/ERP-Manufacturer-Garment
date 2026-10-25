<x-layouts.app :title="'Tagihan '.$bill->number">
    <x-slot:actions>
        @can('payables.manage')
            @if ($bill->canCancel())
                <form method="POST" action="{{ route('bills.cancel', $bill) }}" class="inline" data-confirm="Tagihan dibatalkan dan barangnya bisa ditagihkan ulang." data-confirm-button="Ya, batalkan">
                    @csrf
                    <button type="submit" class="btn-secondary !text-red-600">Batalkan</button>
                </form>
            @endif
            @if ($bill->isOpen())
                <a href="{{ route('disbursements.create', ['document_id' => $bill->id]) }}" class="btn-success">Bayar Tagihan</a>
            @endif
        @endcan
    </x-slot:actions>

    @include('invoices.partials.document', ['doc' => $bill, 'party' => $bill->supplier, 'source' => $bill->purchaseOrder, 'sourceRoute' => 'purchase-orders.show', 'sourceLabel' => 'Purchase Order', 'sourceCan' => 'purchase.view'])
</x-layouts.app>
