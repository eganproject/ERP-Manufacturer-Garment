@extends('pdf.layout')
@section('title', 'SALES ORDER')
@section('number', $order->number)

@section('content')
    <table class="info">
        <tr>
            <td style="width:55%; padding-right:10px;">
                <div class="box">
                    <div class="label">Pelanggan</div>
                    <div class="bold">{{ $order->customer->name }}</div>
                    @if ($order->customer->address)<div>{{ $order->customer->address }}</div>@endif
                    <div class="muted">
                        @if ($order->customer->contact_person)Up. {{ $order->customer->contact_person }}@endif
                        @if ($order->customer->phone) · {{ $order->customer->phone }}@endif
                    </div>
                </div>
            </td>
            <td>
                <table class="kv">
                    <tr><td class="k">Tanggal Order</td><td>: {{ tanggal($order->order_date) }}</td></tr>
                    <tr><td class="k">Target Kirim</td><td>: {{ tanggal($order->delivery_date) }}</td></tr>
                    <tr><td class="k">Status</td><td>: {{ $order->status->label() }}</td></tr>
                    <tr><td class="k">Sales</td><td>: {{ $order->creator?->name ?? '-' }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr><th style="width:4%">No</th><th style="width:18%">SKU</th><th>Produk</th><th class="r" style="width:10%">Qty</th><th style="width:8%">Satuan</th><th class="r" style="width:15%">Harga</th><th class="r" style="width:17%">Subtotal</th></tr>
        </thead>
        <tbody>
            @foreach ($order->items as $i => $item)
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
            <tr><td colspan="6" class="r">Subtotal</td><td class="r">{{ rupiah($order->subtotal) }}</td></tr>
            @if ((float) $order->discount > 0)
                <tr><td colspan="6" class="r">Diskon</td><td class="r">- {{ rupiah($order->discount) }}</td></tr>
            @endif
            <tr><td colspan="6" class="r">TOTAL</td><td class="r">{{ rupiah($order->total) }}</td></tr>
        </tfoot>
    </table>
    <div class="terbilang">Terbilang: {{ ucfirst(terbilang($order->total)) }} rupiah</div>

    @if ($order->notes)
        <div class="notes"><span class="bold">Catatan:</span> {{ $order->notes }}</div>
    @endif

    @include('pdf.partials.signatures', [
        'place' => trim(($company['city'] ? $company['city'].', ' : '').tanggal($order->order_date)),
        'signatures' => [['Hormat kami,', $order->creator?->name], ['Disetujui oleh,', null], ['Pelanggan,', null]],
    ])
@endsection
