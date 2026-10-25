@extends('pdf.layout')
@section('title', 'INVOICE')
@section('number', $invoice->number)

@section('content')
    @if ($invoice->status === \App\Enums\InvoiceStatus::Cancelled)
        <div style="text-align:center; color:#dc2626; font-weight:bold; font-size:14px; border:2px solid #dc2626; padding:4px; margin-bottom:8px;">DIBATALKAN</div>
    @elseif ($invoice->status === \App\Enums\InvoiceStatus::Paid)
        <div style="text-align:center; color:#059669; font-weight:bold; font-size:14px; border:2px solid #059669; padding:4px; margin-bottom:8px;">LUNAS</div>
    @endif

    <table class="info">
        <tr>
            <td style="width:55%; padding-right:10px;">
                <div class="box">
                    <div class="label">Ditagihkan kepada</div>
                    <div class="bold">{{ $invoice->customer->name }}</div>
                    @if ($invoice->customer->address)<div>{{ $invoice->customer->address }}</div>@endif
                    <div class="muted">
                        @if ($invoice->customer->contact_person)Up. {{ $invoice->customer->contact_person }}@endif
                        @if ($invoice->customer->phone) · {{ $invoice->customer->phone }}@endif
                    </div>
                    @if ($invoice->customer->tax_number)<div class="muted">NPWP {{ $invoice->customer->tax_number }}</div>@endif
                </div>
            </td>
            <td>
                <table class="kv">
                    <tr><td class="k">Tanggal Invoice</td><td>: {{ tanggal($invoice->invoice_date) }}</td></tr>
                    <tr><td class="k">Jatuh Tempo</td><td>: <span class="bold">{{ tanggal($invoice->due_date) }}</span></td></tr>
                    <tr><td class="k">No. SO</td><td>: {{ $invoice->salesOrder->number }}</td></tr>
                    @if ($invoice->salesOrder->deliveries->isNotEmpty())
                        <tr><td class="k">Surat Jalan</td><td>: {{ $invoice->salesOrder->deliveries->pluck('number')->implode(', ') }}</td></tr>
                    @endif
                </table>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr><th style="width:4%">No</th><th style="width:18%">SKU</th><th>Produk</th><th class="r" style="width:10%">Qty</th><th style="width:8%">Satuan</th><th class="r" style="width:15%">Harga</th><th class="r" style="width:17%">Subtotal</th></tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $i => $item)
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $item->variant->sku }}</td>
                    <td>{{ $item->variant->display_name }}</td>
                    <td class="r">{{ qty($item->quantity) }}</td>
                    <td>{{ $item->variant->product->unit?->code }}</td>
                    <td class="r">{{ rupiah($item->price) }}</td>
                    <td class="r">{{ rupiah($item->subtotal) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="6" class="r">Subtotal</td><td class="r">{{ rupiah($invoice->subtotal) }}</td></tr>
            @if ((float) $invoice->discount > 0)
                <tr><td colspan="6" class="r">Diskon</td><td class="r">- {{ rupiah($invoice->discount) }}</td></tr>
            @endif
            @if ((float) $invoice->tax_rate > 0)
                <tr><td colspan="6" class="r">PPN {{ qty($invoice->tax_rate) }}%</td><td class="r">{{ rupiah($invoice->tax_amount) }}</td></tr>
            @endif
            <tr><td colspan="6" class="r">TOTAL TAGIHAN</td><td class="r">{{ rupiah($invoice->total) }}</td></tr>
            @if ((float) $invoice->credited_amount > 0)
                <tr><td colspan="6" class="r">Nota Kredit Retur</td><td class="r">- {{ rupiah($invoice->credited_amount) }}</td></tr>
            @endif
            @if ((float) $invoice->paid_amount > 0 || (float) $invoice->credited_amount > 0)
                <tr><td colspan="6" class="r">Sudah Dibayar</td><td class="r">- {{ rupiah($invoice->paid_amount) }}</td></tr>
                <tr><td colspan="6" class="r">SISA TAGIHAN</td><td class="r">{{ rupiah($invoice->outstanding()) }}</td></tr>
            @endif
        </tfoot>
    </table>
    <div class="terbilang">Terbilang: {{ ucfirst(terbilang($invoice->total)) }} rupiah</div>

    <table style="margin-top:10px;">
        <tr>
            <td style="width:55%; vertical-align:top; padding-right:10px;">
                @if ($settings['bank_info'])
                    <div class="box">
                        <div class="label">Pembayaran ditransfer ke</div>
                        <div>{!! nl2br(e($settings['bank_info'])) !!}</div>
                    </div>
                @endif
            </td>
            <td style="vertical-align:top;">
                @if ($invoice->notes)<div><span class="bold">Catatan:</span> {{ $invoice->notes }}</div>@endif
                @if ($settings['invoice_notes'])<div class="muted small" style="margin-top:4px;">{{ $settings['invoice_notes'] }}</div>@endif
            </td>
        </tr>
    </table>

    @include('pdf.partials.signatures', [
        'place' => trim(($company['city'] ? $company['city'].', ' : '').tanggal($invoice->invoice_date)),
        'signatures' => [['Penerima,', null], ['Hormat kami,', $invoice->creator?->name]],
    ])
@endsection
