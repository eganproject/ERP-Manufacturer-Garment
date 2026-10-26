@extends('pdf.layout')
@section('title', 'BUKTI PENERIMAAN BARANG')
@section('number', $receipt->number)

@section('content')
    @php $po = $receipt->purchaseOrder; @endphp
    <table class="info">
        <tr>
            <td style="width:55%; padding-right:10px;">
                <div class="box">
                    <div class="label">Diterima dari Supplier</div>
                    <div class="bold">{{ $po->supplier->name }}</div>
                    @if ($po->supplier->address)<div>{{ $po->supplier->address }}</div>@endif
                    @if ($po->supplier->phone)<div class="muted">{{ $po->supplier->phone }}</div>@endif
                </div>
            </td>
            <td>
                <table class="kv">
                    <tr><td class="k">Tanggal Terima</td><td>: {{ tanggal($receipt->receipt_date) }}</td></tr>
                    <tr><td class="k">No. PO</td><td>: {{ $po->number }}</td></tr>
                    <tr><td class="k">No. SJ Supplier</td><td>: {{ $receipt->delivery_note ?: '-' }}</td></tr>
                    @if (\App\Models\Warehouse::isMulti())<tr><td class="k">Gudang</td><td>: {{ $receipt->warehouse?->name }}</td></tr>@endif
                    <tr><td class="k">Penerima</td><td>: {{ $receipt->creator?->name ?? '-' }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr><th style="width:5%">No</th><th style="width:18%">Kode</th><th>Nama Bahan</th><th class="r" style="width:13%">Dipesan</th><th class="r" style="width:13%">Diterima</th><th style="width:9%">Satuan</th><th style="width:14%">Kondisi</th></tr>
        </thead>
        <tbody>
            @foreach ($receipt->items as $i => $item)
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $item->material->code }}</td>
                    <td>{{ $item->material->name }}{{ $item->material->color ? " ({$item->material->color})" : "" }}</td>
                    <td class="r">{{ qty($item->purchaseOrderItem?->quantity) }}</td>
                    <td class="r bold">{{ qty($item->quantity) }}</td>
                    <td>{{ $item->material->unit->code }}</td>
                    <td></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($receipt->notes)
        <div class="notes"><span class="bold">Catatan:</span> {{ $receipt->notes }}</div>
    @endif

    @include('pdf.partials.signatures', [
        'place' => trim(($company['city'] ? $company['city'].', ' : '').tanggal($receipt->receipt_date)),
        'signatures' => [['Pengirim,', null], ['Diterima oleh (Gudang),', $receipt->creator?->name, 'warehouse'], ['Diperiksa oleh (QC),', null]],
    ])
@endsection
