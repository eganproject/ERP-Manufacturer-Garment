@php $showCost = auth()->user()?->can('costing.view'); @endphp
@extends('pdf.layout')
@section('title', strtoupper($handling->type->label()))
@section('number', $handling->number)

@section('content')
    @if ($handling->isCancelled())
        <div class="stamp stamp-red">DIBATALKAN</div>
    @endif

    <table class="kv" style="width:60%;">
        <tr><td class="k">Tanggal</td><td>: {{ tanggal($handling->date) }}</td></tr>
        <tr><td class="k">Jenis</td><td>: {{ $handling->type->label() }}</td></tr>
        @if ($handling->warehouse)<tr><td class="k">Gudang</td><td>: {{ $handling->warehouse->name }}</td></tr>@endif
        @if ($handling->notes)<tr><td class="k">Catatan</td><td>: {{ $handling->notes }}</td></tr>@endif
    </table>

    <table class="items">
        <thead><tr><th style="width:5%">No</th><th style="width:20%">SKU</th><th>Produk</th><th class="r" style="width:10%">Qty</th><th style="width:8%">Satuan</th>@if ($showCost)<th class="r" style="width:14%">HPP / pcs</th><th class="r" style="width:15%">Nilai</th>@endif</tr></thead>
        <tbody>
            @foreach ($handling->items as $i => $item)
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $item->variant->sku }}</td>
                    <td>{{ $item->variant->display_name }}</td>
                    <td class="r">{{ qty($item->quantity) }}</td>
                    <td>{{ $item->variant->product->unit?->code }}</td>
                    @if ($showCost)<td class="r">{{ rupiah($item->unit_cost, false) }}</td><td class="r">{{ rupiah($item->cost, false) }}</td>@endif
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="3" class="r">TOTAL</td><td class="r">{{ qty($handling->totalQuantity()) }}</td><td></td>@if ($showCost)<td></td><td class="r">{{ rupiah($handling->total_cost, false) }}</td>@endif</tr>
            @if ($showCost && (float) $handling->extra_cost > 0)
                <tr><td colspan="6" class="r">Biaya perbaikan</td><td class="r">{{ rupiah($handling->extra_cost, false) }}</td></tr>
            @endif
        </tfoot>
    </table>

    @include('pdf.partials.signatures', [
        'place' => trim(($company['city'] ? $company['city'].', ' : '').tanggal($handling->date)),
        'signatures' => [['Dibuat oleh,', $handling->creator?->name], ['Kepala Gudang,', null, 'warehouse'], ['Mengetahui,', null, 'production']],
    ])
@endsection
