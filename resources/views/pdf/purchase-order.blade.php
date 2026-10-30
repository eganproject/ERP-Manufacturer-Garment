@extends('pdf.layout')
@section('title', 'PURCHASE ORDER')
@section('number', $order->number)

@section('content')
    <table class="info">
        <tr>
            <td style="width:55%; padding-right:10px;">
                <div class="box">
                    <div class="label">Kepada Supplier</div>
                    <div class="bold">{{ $order->supplier->name }}</div>
                    @if ($order->supplier->address)<div>{{ $order->supplier->address }}</div>@endif
                    <div class="muted">
                        @if ($order->supplier->contact_person)Up. {{ $order->supplier->contact_person }}@endif
                        @if ($order->supplier->phone) · {{ $order->supplier->phone }}@endif
                    </div>
                </div>
            </td>
            <td>
                <table class="kv">
                    <tr><td class="k">Tanggal PO</td><td>: {{ tanggal($order->order_date) }}</td></tr>
                    <tr><td class="k">Estimasi Datang</td><td>: {{ tanggal($order->expected_date) }}</td></tr>
                    <tr><td class="k">Status</td><td>: {{ $order->status->label() }}</td></tr>
                    <tr><td class="k">Dibuat oleh</td><td>: {{ $order->creator?->name ?? '-' }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr><th style="width:4%">No</th><th style="width:16%">Kode</th><th>Nama Bahan</th><th class="r" style="width:12%">Qty</th><th style="width:8%">Satuan</th><th class="r" style="width:15%">Harga</th><th class="r" style="width:17%">Subtotal</th></tr>
        </thead>
        <tbody>
            @foreach ($order->items as $i => $item)
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $item->material->code }}</td>
                    <td>{{ $item->material->name }}{{ $item->material->color ? " ({$item->material->color})" : "" }}</td>
                    <td class="r">{{ qty($item->quantity) }}</td>
                    <td>{{ $item->unitCode() }}</td>
                    <td class="r">{{ rupiah($item->price) }}</td>
                    <td class="r">{{ rupiah($item->subtotal) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="6" class="r">TOTAL</td><td class="r">{{ rupiah($order->total) }}</td></tr>
        </tfoot>
    </table>
    <div class="terbilang">Terbilang: {{ ucfirst(terbilang($order->total)) }} rupiah</div>

    @if ($order->notes)
        <div class="notes"><span class="bold">Catatan:</span> {{ $order->notes }}</div>
    @endif
    @if ($terms = \App\Models\Setting::purchaseTerms())
        <div class="terms"><div class="label">Syarat & Ketentuan</div>{!! nl2br(e($terms)) !!}</div>
    @endif
    <div class="notes small muted">Mohon cantumkan nomor PO ini pada surat jalan dan faktur. Barang yang tidak sesuai spesifikasi akan dikembalikan.</div>

    @include('pdf.partials.signatures', [
        'place' => trim(($company['city'] ? $company['city'].', ' : '').tanggal($order->order_date)),
        'signatures' => [['Dibuat oleh,', $order->creator?->name], ['Disetujui oleh,', $order->approver?->name, 'director'], ['Supplier,', null]],
    ])
@endsection
