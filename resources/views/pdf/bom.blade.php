@extends('pdf.layout')
@section('title', 'BILL OF MATERIALS')
@section('number', $bom->product->code)

@section('content')
    <table class="info">
        <tr>
            <td style="width:50%; padding-right:10px;">
                <div class="box">
                    <div class="label">Produk</div>
                    <div class="bold">{{ $bom->product->code }} – {{ $bom->product->name }}</div>
                    <div>{{ $bom->name }}</div>
                </div>
            </td>
            <td>
                <div class="box">
                    <div class="label">Kebutuhan untuk</div>
                    <div class="bold">{{ qty($quantity) }} {{ $bom->product->unit?->code ?? 'pcs' }}</div>
                    <div>Status: {{ $bom->is_active ? 'Aktif' : 'Nonaktif' }}</div>
                </div>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width:5%">No</th><th style="width:16%">Kode</th><th>Bahan</th>
                <th class="r" style="width:12%">Qty / pcs</th><th class="r" style="width:9%">Waste</th><th class="r" style="width:15%">Total Kebutuhan</th>
                @if ($showCost)<th class="r" style="width:15%">Estimasi Biaya</th>@endif
            </tr>
        </thead>
        <tbody>
            @php $total = 0; @endphp
            @foreach ($bom->items as $i => $item)
                @php
                    $need = (float) $item->quantity * (1 + (float) $item->waste_percent / 100) * $quantity;
                    $cost = $need * $item->material->costPrice();
                    $total += $cost;
                @endphp
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $item->material->code }}</td>
                    <td>{{ $item->material->display_name }}@if ($item->scopeLabel())<br><span class="bold">Khusus {{ $item->scopeLabel() }}</span>@endif @if ($item->notes)<br><span style="color:#71717a">{{ $item->notes }}</span>@endif</td>
                    <td class="r">{{ qty($item->quantity, 4) }} {{ $item->material->unit->code }}</td>
                    <td class="r">{{ qty($item->waste_percent) }}%</td>
                    <td class="r bold">{{ qty($need, 2) }} {{ $item->material->unit->code }}</td>
                    @if ($showCost)<td class="r">{{ rupiah($cost) }}</td>@endif
                </tr>
            @endforeach
        </tbody>
        @if ($showCost)
            <tfoot>
                <tr><td colspan="6" class="r bold">Total bahan</td><td class="r bold">{{ rupiah($total) }}</td></tr>
                <tr><td colspan="6" class="r">Upah + overhead standar ({{ qty($quantity) }} pcs)</td><td class="r">{{ rupiah(((float) $bom->labor_cost + (float) $bom->overhead_cost) * $quantity) }}</td></tr>
                <tr><td colspan="6" class="r bold">HPP standar / pcs</td><td class="r bold">{{ rupiah($bom->standardUnitCost()) }}</td></tr>
            </tfoot>
        @endif
    </table>

    @if ($bom->notes)
        <div class="notes"><span class="bold">Catatan:</span> {{ $bom->notes }}</div>
    @endif

    @include('pdf.partials.signatures', [
        'place' => trim(($company['city'] ? $company['city'].', ' : '').tanggal(today())),
        'signatures' => [['Disusun oleh,', auth()->user()?->name], ['Diperiksa (Produksi),', null, 'production']],
    ])
@endsection
