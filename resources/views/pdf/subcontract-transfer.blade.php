@extends('pdf.layout')
@section('title', $transfer->type->documentTitle())
@section('number', $transfer->number)

@section('content')
    @php
        $order = $transfer->subcontractOrder;
        $isDispatch = $transfer->type === \App\Enums\SubcontractTransferType::Dispatch;
    @endphp
    <table class="info">
        <tr>
            <td style="width:55%; padding-right:10px;">
                <div class="box">
                    <div class="label">{{ $isDispatch ? 'Kirim Kepada (Vendor Maklon)' : 'Diretur oleh (Vendor Maklon)' }}</div>
                    <div class="bold">{{ $order->supplier->name }}</div>
                    <div>{{ $order->supplier->address }}</div>
                    <div class="muted">
                        @if ($order->supplier->contact_person)Up. {{ $order->supplier->contact_person }}@endif
                        @if ($order->supplier->phone) · {{ $order->supplier->phone }}@endif
                    </div>
                </div>
            </td>
            <td>
                <table class="kv">
                    <tr><td class="k">Tanggal</td><td>: {{ tanggal($transfer->date) }}</td></tr>
                    <tr><td class="k">Order Maklon</td><td>: {{ $order->number }}</td></tr>
                    <tr><td class="k">No. SPK</td><td>: {{ $order->productionOrder->number }} ({{ $order->productionOrder->product->name }})</td></tr>
                    <tr><td class="k">Pekerjaan</td><td>: {{ $order->stagesLabel() }}, {{ qty($order->quantity) }} pcs</td></tr>
                    @if (\App\Models\Warehouse::isMulti())<tr><td class="k">Gudang</td><td>: {{ $transfer->warehouse?->name }}</td></tr>@endif
                    <tr><td class="k">No. Kendaraan</td><td>: {{ $transfer->vehicle_number ?: '-' }}</td></tr>
                    <tr><td class="k">Sopir / Pengantar</td><td>: {{ $transfer->driver_name ?: '-' }}</td></tr>
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
    <div class="notes small muted">
        @if ($isDispatch)
            Bahan di atas adalah titipan milik {{ $company['name'] }} untuk dikerjakan sesuai order maklon dan bukan untuk diperjualbelikan. Sisa bahan wajib dikembalikan.
        @else
            Sisa bahan di atas telah diterima kembali oleh gudang {{ $company['name'] }}.
        @endif
    </div>

    @include('pdf.partials.signatures', [
        'place' => trim(($company['city'] ? $company['city'].', ' : '').tanggal($transfer->date)),
        'signatures' => $isDispatch
            ? [['Hormat kami (Gudang),', $transfer->creator?->name, 'warehouse'], ['Pengirim / Sopir,', $transfer->driver_name], ['Diterima Vendor,', $order->supplier->contact_person]]
            : [['Diserahkan Vendor,', $order->supplier->contact_person], ['Pengantar,', $transfer->driver_name], ['Diterima Gudang,', $transfer->creator?->name]],
    ])
@endsection
