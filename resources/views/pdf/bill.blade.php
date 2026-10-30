@php $source = $bill->source(); @endphp
@extends('pdf.layout')
@section('title', 'TAGIHAN SUPPLIER')
@section('number', $bill->number)

@section('content')
    @if ($bill->status === \App\Enums\InvoiceStatus::Cancelled)
        <div class="stamp stamp-red">DIBATALKAN</div>
    @elseif ($bill->status === \App\Enums\InvoiceStatus::Paid)
        <div class="stamp stamp-green">LUNAS</div>
    @endif

    <table class="info">
        <tr>
            <td style="width:55%; padding-right:12px;">
                <div class="box">
                    <div class="label">Supplier</div>
                    <div class="bold">{{ $bill->supplier->name }}</div>
                    @if ($bill->supplier->address)<div>{{ $bill->supplier->address }}</div>@endif
                    <div class="muted">
                        @if ($bill->supplier->contact_person)Up. {{ $bill->supplier->contact_person }}@endif
                        @if ($bill->supplier->phone) · {{ $bill->supplier->phone }}@endif
                    </div>
                    @if ($bill->supplier->tax_number)<div class="muted">NPWP {{ $bill->supplier->tax_number }}</div>@endif
                </div>
            </td>
            <td>
                <table class="kv">
                    <tr><td class="k">Tanggal Tagihan</td><td>: {{ tanggal($bill->bill_date) }}</td></tr>
                    <tr><td class="k">Jatuh Tempo</td><td>: <span class="bold">{{ tanggal($bill->due_date) }}</span></td></tr>
                    @if ($bill->supplier_invoice_number)<tr><td class="k">No. Faktur Supplier</td><td>: {{ $bill->supplier_invoice_number }}</td></tr>@endif
                    @if ($bill->tax_invoice_number)<tr><td class="k">No. Faktur Pajak</td><td>: {{ $bill->tax_invoice_number }}</td></tr>@endif
                    @if ($source)
                        <tr><td class="k">{{ $bill->subcontractOrder ? 'Order Maklon' : ($bill->fixedAsset ? 'Aset Tetap' : 'No. PO') }}</td><td>: {{ $source->number }}</td></tr>
                    @endif
                    <tr><td class="k">Status</td><td>: {{ $bill->status->label() }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead><tr><th style="width:5%">No</th><th>Bahan / Jasa</th><th class="r" style="width:12%">Qty</th><th style="width:8%">Satuan</th><th class="r" style="width:16%">Harga</th><th class="r" style="width:18%">Subtotal</th></tr></thead>
        <tbody>
            @foreach ($bill->items as $i => $item)
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $item->material?->display_name ?? $item->description }}</td>
                    <td class="r">{{ qty($item->quantity) }}</td>
                    <td>{{ $item->purchaseOrderItem?->unitCode() ?? $item->material?->unit?->code ?? 'pcs' }}</td>
                    <td class="r">{{ rupiah($item->price) }}</td>
                    <td class="r">{{ rupiah($item->subtotal) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="5" class="r">Subtotal</td><td class="r">{{ rupiah($bill->subtotal) }}</td></tr>
            @if ((float) $bill->discount > 0)
                <tr><td colspan="5" class="r">Diskon</td><td class="r">- {{ rupiah($bill->discount) }}</td></tr>
            @endif
            @if ((float) $bill->tax_rate > 0)
                <tr><td colspan="5" class="r">PPN {{ qty($bill->tax_rate) }}%</td><td class="r">{{ rupiah($bill->tax_amount) }}</td></tr>
            @endif
            <tr><td colspan="5" class="r">TOTAL TAGIHAN</td><td class="r">{{ rupiah($bill->total) }}</td></tr>
            @if ((float) $bill->credited_amount > 0)
                <tr><td colspan="5" class="r">Nota Debit Retur</td><td class="r">- {{ rupiah($bill->credited_amount) }}</td></tr>
            @endif
            @if ((float) $bill->paid_amount > 0 || (float) $bill->credited_amount > 0)
                <tr><td colspan="5" class="r">Sudah Dibayar</td><td class="r">- {{ rupiah($bill->paid_amount) }}</td></tr>
                <tr><td colspan="5" class="r">SISA HUTANG</td><td class="r">{{ rupiah($bill->outstanding()) }}</td></tr>
            @endif
        </tfoot>
    </table>
    <div class="terbilang">Terbilang: {{ ucfirst(terbilang($bill->total)) }} rupiah</div>

    @if ($bill->allocations->isNotEmpty())
        <div class="section-title">Riwayat Pembayaran</div>
        <table class="items" style="margin-top:2px;">
            <thead><tr><th>No. Bukti</th><th>Tanggal</th><th>Metode</th><th class="r">Jumlah</th></tr></thead>
            <tbody>
                @foreach ($bill->allocations->sortBy('payment.payment_date') as $allocation)
                    <tr @if ($allocation->payment->isCancelled()) style="color:#a1a1aa; text-decoration:line-through;" @endif>
                        <td>{{ $allocation->payment->number }}</td>
                        <td>{{ tanggal($allocation->payment->payment_date) }}</td>
                        <td>{{ $allocation->payment->method->label() }}</td>
                        <td class="r">{{ rupiah($allocation->amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if ($bill->notes)
        <div class="notes"><span class="bold">Catatan:</span> {{ $bill->notes }}</div>
    @endif

    @include('pdf.partials.signatures', [
        'place' => trim(($company['city'] ? $company['city'].', ' : '').tanggal($bill->bill_date)),
        'signatures' => [['Dicatat oleh,', $bill->creator?->name], ['Diperiksa,', null, 'finance'], ['Disetujui,', null, 'director']],
    ])
@endsection
