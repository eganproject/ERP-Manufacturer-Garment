@extends('pdf.layout')
@section('title', 'SURAT JALAN')
@section('number', $delivery->number)

@section('content')
    @php $so = $delivery->salesOrder; @endphp
    <table class="info">
        <tr>
            <td style="width:55%; padding-right:10px;">
                <div class="box">
                    <div class="label">Kirim Kepada</div>
                    <div class="bold">{{ $so->customer->name }}</div>
                    <div>{{ $delivery->shipping_address ?: $so->customer->address }}</div>
                    <div class="muted">
                        @if ($so->customer->contact_person)Up. {{ $so->customer->contact_person }}@endif
                        @if ($so->customer->phone) · {{ $so->customer->phone }}@endif
                    </div>
                </div>
            </td>
            <td>
                <table class="kv">
                    <tr><td class="k">Tanggal Kirim</td><td>: {{ tanggal($delivery->delivery_date) }}</td></tr>
                    <tr><td class="k">No. SO</td><td>: {{ $so->number }}</td></tr>
                    @if (\App\Models\Warehouse::isMulti())<tr><td class="k">Gudang</td><td>: {{ $delivery->warehouse?->name }}</td></tr>@endif
                    <tr><td class="k">No. Kendaraan</td><td>: {{ $delivery->vehicle_number ?: '-' }}</td></tr>
                    <tr><td class="k">Sopir / Ekspedisi</td><td>: {{ $delivery->driver_name ?: '-' }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr><th style="width:5%">No</th><th style="width:20%">SKU</th><th>Nama Barang</th><th class="r" style="width:12%">Jumlah</th><th style="width:10%">Satuan</th><th style="width:20%">Keterangan</th></tr>
        </thead>
        <tbody>
            @foreach ($delivery->items as $i => $item)
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $item->variant->sku }}</td>
                    <td>{{ $item->variant->display_name }}</td>
                    <td class="r bold">{{ qty($item->quantity) }}</td>
                    <td>{{ $item->variant->product->unit?->code }}</td>
                    <td></td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="3" class="r">TOTAL</td><td class="r">{{ qty($delivery->items->sum('quantity')) }}</td><td colspan="2"></td></tr>
        </tfoot>
    </table>

    @if ($delivery->notes)
        <div class="notes"><span class="bold">Catatan:</span> {{ $delivery->notes }}</div>
    @endif
    <div class="notes small muted">Barang telah diterima dalam keadaan baik dan jumlah yang benar. Komplain maksimal 3 hari setelah barang diterima.</div>

    @include('pdf.partials.signatures', [
        'place' => trim(($company['city'] ? $company['city'].', ' : '').tanggal($delivery->delivery_date)),
        'signatures' => [['Hormat kami (Gudang),', $delivery->creator?->name, 'warehouse'], ['Pengirim / Sopir,', $delivery->driver_name], ['Penerima,', null]],
    ])
@endsection
