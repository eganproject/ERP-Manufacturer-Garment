@extends('pdf.layout')
@section('title', 'TANDA TERIMA HASIL MAKLON')
@section('number', $receipt->number)

@section('content')
    @php $order = $receipt->subcontractOrder; @endphp
    <table class="info">
        <tr>
            <td style="width:55%; padding-right:10px;">
                <div class="box">
                    <div class="label">Diterima dari Vendor Maklon</div>
                    <div class="bold">{{ $order->supplier->name }}</div>
                    <div>{{ $order->supplier->address }}</div>
                    <div class="muted">@if ($order->supplier->phone){{ $order->supplier->phone }}@endif</div>
                </div>
            </td>
            <td>
                <table class="kv">
                    <tr><td class="k">Tanggal Terima</td><td>: {{ tanggal($receipt->date) }}</td></tr>
                    <tr><td class="k">Order Maklon</td><td>: {{ $order->number }}</td></tr>
                    <tr><td class="k">No. SPK</td><td>: {{ $order->productionOrder->number }}</td></tr>
                    <tr><td class="k">SJ Vendor</td><td>: {{ $receipt->vendor_reference ?: '-' }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead><tr><th>Uraian</th><th class="r" style="width:13%">Bagus</th><th class="r" style="width:13%">Reject</th><th class="r" style="width:16%">Ongkos / pcs</th><th class="r" style="width:18%">Ongkos Jasa</th></tr></thead>
        <tbody>
            <tr>
                <td>{{ $order->productionOrder->product->name }} – {{ $order->stagesLabel() }}</td>
                <td class="r bold">{{ qty($receipt->good_quantity) }}</td>
                <td class="r">{{ qty($receipt->reject_quantity) }}</td>
                <td class="r">{{ rupiah($receipt->rate) }}</td>
                <td class="r bold">{{ rupiah($receipt->amount) }}</td>
            </tr>
        </tbody>
    </table>
    <table class="kv" style="margin-top:8px; width:60%;">
        <tr><td class="k">Total order</td><td>: {{ qty($order->quantity) }} pcs</td></tr>
        <tr><td class="k">Total diterima s.d. ini</td><td>: {{ qty($order->received_quantity) }} bagus, {{ qty($order->reject_quantity) }} reject</td></tr>
        <tr><td class="k">Sisa di vendor</td><td>: {{ qty($order->outstandingQuantity()) }} pcs</td></tr>
    </table>

    @if ($receipt->notes)
        <div class="notes"><span class="bold">Catatan:</span> {{ $receipt->notes }}</div>
    @endif

    @include('pdf.partials.signatures', [
        'place' => trim(($company['city'] ? $company['city'].', ' : '').tanggal($receipt->date)),
        'signatures' => [['Diserahkan Vendor,', $order->supplier->contact_person], ['QC,', null], ['Diterima,', $receipt->creator?->name]],
    ])
@endsection
