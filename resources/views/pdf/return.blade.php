@extends('pdf.layout')
@section('title', $kind->isSales ? 'NOTA RETUR PENJUALAN' : 'NOTA RETUR PEMBELIAN')
@section('number', $return->number)

@php
    $party = $return->{$kind->partyRelation};
    $order = $return->{$kind->orderRelation};
@endphp

@section('content')
    @if (! $return->isPosted())
        <div style="text-align:center; color:#dc2626; font-weight:bold; font-size:14px; border:2px solid #dc2626; padding:4px; margin-bottom:8px;">DIBATALKAN</div>
    @endif

    <table class="info">
        <tr>
            <td style="width:55%; padding-right:10px;">
                <div class="box">
                    <div class="label">{{ $kind->isSales ? 'Retur dari pelanggan' : 'Dikembalikan kepada supplier' }}</div>
                    <div class="bold">{{ $party->name }}</div>
                    @if ($party->address)<div>{{ $party->address }}</div>@endif
                    <div class="muted">
                        @if ($party->contact_person)Up. {{ $party->contact_person }}@endif
                        @if ($party->phone) · {{ $party->phone }}@endif
                    </div>
                    @if ($party->tax_number)<div class="muted">NPWP {{ $party->tax_number }}</div>@endif
                </div>
            </td>
            <td>
                <table class="kv">
                    <tr><td class="k">Tanggal Retur</td><td>: {{ tanggal($return->return_date) }}</td></tr>
                    <tr><td class="k">No. {{ $kind->isSales ? 'SO' : 'PO' }}</td><td>: {{ $order->number }}</td></tr>
                    @if ($return->applications->isNotEmpty())
                        <tr><td class="k">Atas {{ $kind->documentLabel }}</td><td>: {{ $return->applications->map(fn ($a) => $a->payable->number)->unique()->implode(', ') }}</td></tr>
                    @endif
                    <tr><td class="k">Alasan</td><td>: {{ $return->reason }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr><th style="width:4%">No</th><th style="width:18%">Kode</th><th>{{ $kind->itemLabel }}</th><th class="r" style="width:10%">Qty Retur</th><th style="width:8%">Satuan</th><th class="r" style="width:10%">Ditagih</th><th class="r" style="width:13%">Harga</th><th class="r" style="width:15%">Nilai Nota</th></tr>
        </thead>
        <tbody>
            @foreach ($return->items as $i => $item)
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    @if ($kind->isSales)
                        <td>{{ $item->variant->sku }}</td>
                        <td>{{ $item->variant->display_name }}</td>
                        <td class="r">{{ qty($item->quantity) }}</td>
                        <td>{{ $item->variant->product->unit?->code }}</td>
                    @else
                        <td>{{ $item->material->code }}</td>
                        <td>{{ $item->material->name }}</td>
                        <td class="r">{{ qty($item->quantity) }}</td>
                        <td>{{ $item->material->unit?->code }}</td>
                    @endif
                    <td class="r">{{ qty($item->billed_quantity) }}</td>
                    <td class="r">{{ rupiah($item->price) }}</td>
                    <td class="r">{{ rupiah($item->amount) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="7" class="r">DPP</td><td class="r">{{ rupiah($return->amount) }}</td></tr>
            @if ((float) $return->tax_rate > 0)
                <tr><td colspan="7" class="r">PPN {{ qty($return->tax_rate) }}%</td><td class="r">{{ rupiah($return->tax_amount) }}</td></tr>
            @endif
            <tr><td colspan="7" class="r">TOTAL {{ strtoupper($kind->noteLabel) }}</td><td class="r">{{ rupiah($return->total) }}</td></tr>
        </tfoot>
    </table>
    @if ((float) $return->total > 0)
        <div class="terbilang">Terbilang: {{ ucfirst(terbilang($return->total)) }} rupiah</div>
    @endif
    <p class="muted small" style="margin-top:6px;">
        {{ $kind->noteLabel }} ini mengurangi {{ $kind->isSales ? 'piutang pelanggan' : 'hutang kepada supplier' }} atas barang yang sudah ditagih. Barang yang belum ditagih tidak akan ditagihkan.
    </p>
    @if ($return->notes)<div class="notes"><span class="bold">Catatan:</span> {{ $return->notes }}</div>@endif

    @include('pdf.partials.signatures', [
        'place' => trim(($company['city'] ? $company['city'].', ' : '').tanggal($return->return_date)),
        'signatures' => $kind->isSales
            ? [['Pelanggan,', null], ['Gudang,', null], ['Hormat kami,', $return->creator?->name]]
            : [['Supplier,', null], ['Gudang,', null], ['Hormat kami,', $return->creator?->name]],
    ])
@endsection
