@extends('pdf.layout')
@section('title', $transfer->type->documentTitle())
@section('number', $transfer->number)

@section('content')
    @php
        $order = $transfer->productionOrder;
        $isIssue = $transfer->type === \App\Enums\ProductionMaterialTransferType::Issue;
    @endphp
    <table class="info">
        <tr>
            <td style="width:55%; padding-right:10px;">
                <div class="box">
                    <div class="label">Untuk SPK Produksi</div>
                    <div class="bold">{{ $order->number }}</div>
                    <div>{{ $order->product->code }} - {{ $order->product->name }}</div>
                    <div class="muted">{{ qty($order->quantity) }} pcs</div>
                </div>
            </td>
            <td>
                <table class="kv">
                    <tr><td class="k">Tanggal</td><td>: {{ tanggal($transfer->date) }}</td></tr>
                    <tr><td class="k">Jenis</td><td>: {{ $transfer->type->label() }}</td></tr>
                    @if (\App\Models\Warehouse::isMulti())<tr><td class="k">Gudang</td><td>: {{ $transfer->warehouse?->name }}</td></tr>@endif
                </table>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead><tr><th style="width:5%">No</th><th style="width:18%">Kode</th><th>Nama Bahan</th><th class="r" style="width:14%">Jumlah</th><th style="width:10%">Satuan</th><th style="width:22%">Keterangan</th></tr></thead>
        <tbody>
            @foreach ($transfer->items as $i => $item)
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $item->material->code }}</td>
                    <td>{{ $item->material->name }}{{ $item->material->color ? ' ('.$item->material->color.')' : '' }}</td>
                    <td class="r bold">{{ qty($item->quantity) }}</td>
                    <td>{{ $item->material->unit?->code }}</td>
                    <td></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($transfer->notes)
        <div class="notes"><span class="bold">Catatan:</span> {{ $transfer->notes }}</div>
    @endif

    @include('pdf.partials.signatures', [
        'place' => trim(($company['city'] ? $company['city'].', ' : '').tanggal($transfer->date)),
        'signatures' => $isIssue
            ? [['Diminta (Produksi),', null, 'production'], ['Dikeluarkan (Gudang),', $transfer->creator?->name, 'warehouse']]
            : [['Diserahkan (Produksi),', null, 'production'], ['Diterima (Gudang),', $transfer->creator?->name, 'warehouse']],
    ])
@endsection
