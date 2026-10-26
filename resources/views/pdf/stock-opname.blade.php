@extends('pdf.layout')
@section('title', $blind ? 'LEMBAR HITUNG STOCK OPNAME' : 'BERITA ACARA STOCK OPNAME')
@section('number', $opname->number)

@section('content')
    <table class="kv" style="margin-bottom:8px;">
        <tr><td class="k">Gudang</td><td>: {{ $opname->warehouse->name }}</td></tr>
        <tr><td class="k">Tanggal</td><td>: {{ tanggal($opname->date) }}</td></tr>
        <tr><td class="k">Jenis Barang</td><td>: {{ $opname->scopeLabel() }}</td></tr>
        <tr><td class="k">Status</td><td>: {{ $opname->status->label() }}</td></tr>
    </table>

    @php $items = $opname->items->sortBy(fn ($i) => \App\Services\WarehouseStockService::itemName($i->stockable))->values(); @endphp
    <table class="items">
        <thead>
            <tr>
                <th style="width:5%">No</th><th style="width:16%">Kode / SKU</th><th>Nama Barang</th><th style="width:8%">Satuan</th>
                @unless ($blind)<th class="r" style="width:11%">Tercatat</th>@endunless
                <th class="r" style="width:11%">Fisik</th>
                @unless ($blind)<th class="r" style="width:11%">Selisih</th>@endunless
                <th style="width:18%">Keterangan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $i => $line)
                @php $s = $line->stockable; $isMaterial = $line->stockable_type === 'material'; @endphp
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $isMaterial ? $s?->code : $s?->sku }}</td>
                    <td>{{ $s?->display_name }}</td>
                    <td>{{ $isMaterial ? $s?->unit?->code : 'pcs' }}</td>
                    @unless ($blind)<td class="r">{{ qty($line->system_quantity) }}</td>@endunless
                    <td class="r bold">{{ $blind ? '' : ($line->isCounted() ? qty($line->counted_quantity) : '-') }}</td>
                    @unless ($blind)<td class="r">{{ $line->isCounted() ? qty($line->difference) : '-' }}</td>@endunless
                    <td>{{ $blind ? '' : $line->notes }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($opname->notes)
        <div class="notes"><span class="bold">Catatan:</span> {{ $opname->notes }}</div>
    @endif

    @include('pdf.partials.signatures', [
        'place' => trim(($company['city'] ? $company['city'].', ' : '').tanggal($opname->date)),
        'signatures' => [['Penghitung,', null], ['Kepala Gudang,', $opname->creator?->name, 'warehouse'], ['Mengetahui,', null, 'director']],
    ])
@endsection
