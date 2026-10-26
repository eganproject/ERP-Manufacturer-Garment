@extends('pdf.layout')
@section('title', 'SURAT TRANSFER ANTAR GUDANG')
@section('number', $transfer->number)

@section('content')
    <table class="info">
        <tr>
            <td style="width:50%; padding-right:10px;">
                <div class="box">
                    <div class="label">Dari Gudang</div>
                    <div class="bold">{{ $transfer->fromWarehouse->name }}</div>
                    @if ($transfer->fromWarehouse->address)<div>{{ $transfer->fromWarehouse->address }}</div>@endif
                </div>
            </td>
            <td>
                <div class="box">
                    <div class="label">Ke Gudang</div>
                    <div class="bold">{{ $transfer->toWarehouse->name }}</div>
                    @if ($transfer->toWarehouse->address)<div>{{ $transfer->toWarehouse->address }}</div>@endif
                </div>
            </td>
        </tr>
    </table>
    <table class="kv" style="margin-bottom:8px;">
        <tr><td class="k">Tanggal</td><td>: {{ tanggal($transfer->date) }}</td></tr>
        <tr><td class="k">Status</td><td>: {{ $transfer->status->label() }}</td></tr>
    </table>

    <table class="items">
        <thead><tr><th style="width:5%">No</th><th style="width:20%">Kode / SKU</th><th>Nama Barang</th><th class="r" style="width:14%">Jumlah</th><th style="width:10%">Satuan</th><th style="width:20%">Keterangan</th></tr></thead>
        <tbody>
            @foreach ($transfer->items as $i => $item)
                @php $s = $item->stockable; $isMaterial = $item->stockable_type === 'material'; @endphp
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $isMaterial ? $s->code : $s->sku }}</td>
                    <td>{{ $isMaterial ? $s->display_name : $s->display_name }}</td>
                    <td class="r bold">{{ qty($item->quantity) }}</td>
                    <td>{{ $isMaterial ? $s->unit?->code : 'pcs' }}</td>
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
        'signatures' => [['Dikeluarkan (Gudang Asal),', $transfer->creator?->name, 'warehouse'], ['Pengantar,', null], ['Diterima (Gudang Tujuan),', null]],
    ])
@endsection
