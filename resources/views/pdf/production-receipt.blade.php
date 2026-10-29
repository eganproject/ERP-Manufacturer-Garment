@extends('pdf.layout')
@section('title', 'BUKTI SETOR HASIL PRODUKSI')
@section('number', $receipt->number)

@section('content')
    @php $order = $receipt->productionOrder; @endphp
    <table class="info">
        <tr>
            <td style="width:55%; padding-right:10px;">
                <div class="box">
                    <div class="label">Dari SPK Produksi</div>
                    <div class="bold">{{ $order->number }}</div>
                    <div>{{ $order->product->code }} - {{ $order->product->name }}</div>
                    <div class="muted">Rencana {{ qty($order->quantity) }} pcs</div>
                </div>
            </td>
            <td>
                <table class="kv">
                    <tr><td class="k">Tanggal</td><td>: {{ tanggal($receipt->date) }}</td></tr>
                    <tr><td class="k">Jenis</td><td>: {{ $receipt->is_final ? 'Setoran penutup (SPK selesai)' : 'Setoran bertahap' }}</td></tr>
                    @if (\App\Models\Warehouse::isMulti())<tr><td class="k">Gudang</td><td>: {{ $receipt->warehouse?->name }}</td></tr>@endif
                </table>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead><tr><th style="width:5%">No</th><th style="width:22%">SKU</th><th>Ukuran / Warna</th><th class="r" style="width:14%">Bagus</th><th class="r" style="width:14%">Grade B</th></tr></thead>
        <tbody>
            @foreach ($receipt->items as $i => $item)
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $item->variant->sku }}</td>
                    <td>{{ $item->variant->size }}{{ $item->variant->color ? ' / '.$item->variant->color : '' }}</td>
                    <td class="r bold">{{ qty($item->quantity) }}</td>
                    <td class="r">{{ qty($item->defect_quantity) }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="3" class="r bold">Total (pcs)</td>
                <td class="r bold">{{ qty($receipt->quantity) }}</td>
                <td class="r bold">{{ qty($receipt->defect_quantity) }}</td>
            </tr>
        </tbody>
    </table>

    @if ($receipt->notes)
        <div class="notes"><span class="bold">Catatan:</span> {{ $receipt->notes }}</div>
    @endif
    <div class="notes small muted">Barang grade B masuk stok barang cacat dan ditangani lewat menu Barang Cacat.</div>

    @include('pdf.partials.signatures', [
        'place' => trim(($company['city'] ? $company['city'].', ' : '').tanggal($receipt->date)),
        'signatures' => [['Diserahkan (Produksi),', null, 'production'], ['Diterima (Gudang),', $receipt->creator?->name, 'warehouse']],
    ])
@endsection
