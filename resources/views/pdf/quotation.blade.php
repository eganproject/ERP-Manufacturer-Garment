@extends('pdf.layout')
@section('title', 'PENAWARAN HARGA')
@section('number', $quotation->number)

@section('content')
    <table class="info">
        <tr>
            <td style="width:55%; padding-right:10px;">
                <div class="box">
                    <div class="label">Kepada Yth.</div>
                    <div class="bold">{{ $quotation->customer->name }}</div>
                    @if ($quotation->customer->address)<div>{{ $quotation->customer->address }}</div>@endif
                    <div class="muted">
                        @if ($quotation->customer->contact_person)Up. {{ $quotation->customer->contact_person }}@endif
                        @if ($quotation->customer->phone) · {{ $quotation->customer->phone }}@endif
                    </div>
                </div>
            </td>
            <td>
                <table class="kv">
                    <tr><td class="k">Tanggal</td><td>: {{ tanggal($quotation->quotation_date) }}</td></tr>
                    <tr><td class="k">Berlaku s.d.</td><td>: <span class="bold">{{ tanggal($quotation->valid_until) }}</span></td></tr>
                    @if ($quotation->subject)<tr><td class="k">Perihal</td><td>: {{ $quotation->subject }}</td></tr>@endif
                    @if ($quotation->lead_time_days)<tr><td class="k">Estimasi Selesai</td><td>: {{ $quotation->lead_time_days }} hari setelah order</td></tr>@endif
                    <tr><td class="k">Sales</td><td>: {{ $quotation->creator?->name ?? '-' }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <p>Dengan hormat, bersama ini kami sampaikan penawaran harga sebagai berikut:</p>

    <table class="items">
        <thead>
            <tr><th style="width:4%">No</th><th style="width:16%">SKU</th><th>Produk</th><th class="r" style="width:9%">Qty</th><th style="width:8%">Satuan</th><th class="r" style="width:15%">Harga</th><th class="r" style="width:17%">Subtotal</th></tr>
        </thead>
        <tbody>
            @foreach ($quotation->items as $i => $item)
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $item->variant->sku }}</td>
                    <td>{{ $item->variant->display_name }}@if ($item->description)<div class="muted">{{ $item->description }}</div>@endif</td>
                    <td class="r">{{ qty($item->quantity) }}</td>
                    <td>{{ $item->variant->product->unit?->code }}</td>
                    <td class="r">{{ rupiah($item->price) }}</td>
                    <td class="r">{{ rupiah($item->subtotal) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="6" class="r">Subtotal</td><td class="r">{{ rupiah($quotation->subtotal) }}</td></tr>
            @if ((float) $quotation->discount > 0)
                <tr><td colspan="6" class="r">Diskon</td><td class="r">- {{ rupiah($quotation->discount) }}</td></tr>
            @endif
            @if ((float) $quotation->tax_rate > 0)
                <tr><td colspan="6" class="r">PPN {{ qty($quotation->tax_rate) }}%</td><td class="r">{{ rupiah($quotation->tax_amount) }}</td></tr>
            @endif
            <tr><td colspan="6" class="r">TOTAL</td><td class="r">{{ rupiah($quotation->total) }}</td></tr>
        </tfoot>
    </table>
    <div class="terbilang">Terbilang: {{ ucfirst(terbilang($quotation->total)) }} rupiah</div>

    @if ($quotation->terms)
        <div class="terms"><div class="label">Syarat & Ketentuan</div>{!! nl2br(e($quotation->terms)) !!}</div>
    @endif
    @include('pdf.partials.bank', ['style' => 'margin-top:10px;'])

    @include('pdf.partials.signatures', [
        'place' => trim(($company['city'] ? $company['city'].', ' : '').tanggal($quotation->quotation_date)),
        'signatures' => [['Hormat kami,', $quotation->creator?->name], ['Disetujui pelanggan,', null]],
    ])
@endsection
