@extends('pdf.layout')
@section('title', 'ORDER MAKLON')
@section('number', $order->number)

@section('content')
    @php $spk = $order->productionOrder; @endphp
    <table class="info">
        <tr>
            <td style="width:55%; padding-right:10px;">
                <div class="box">
                    <div class="label">Kepada Vendor Maklon</div>
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
                    <tr><td class="k">Tanggal Order</td><td>: {{ tanggal($order->order_date) }}</td></tr>
                    <tr><td class="k">Target Kembali</td><td>: {{ tanggal($order->due_date) }}</td></tr>
                    <tr><td class="k">No. SPK</td><td>: {{ $spk->number }}</td></tr>
                    <tr><td class="k">Produk</td><td>: {{ $spk->product->code }} - {{ $spk->product->name }}</td></tr>
                    <tr><td class="k">Pekerjaan</td><td>: {{ $order->stagesLabel() }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="section-title">Jumlah & Ongkos Jasa</div>
    <table class="items" style="margin-top:4px;">
        <thead><tr><th>Uraian</th><th class="r" style="width:15%">Jumlah</th><th class="r" style="width:20%">Ongkos / pcs</th><th class="r" style="width:22%">Estimasi Ongkos</th></tr></thead>
        <tbody>
            <tr>
                <td>Jasa {{ $order->stagesLabel() }} – {{ $spk->product->name }}</td>
                <td class="r bold">{{ qty($order->quantity) }} pcs</td>
                <td class="r">{{ rupiah($order->rate) }}</td>
                <td class="r">{{ rupiah((float) $order->quantity * (float) $order->rate) }}</td>
            </tr>
        </tbody>
    </table>
    <div class="small muted" style="margin-top:3px;">Ongkos dibayar untuk hasil bagus. Barang reject dikembalikan bersama hasil dan tidak dibayar.</div>

    @if ($spk->items->isNotEmpty() && (float) $order->quantity === (float) $spk->quantity)
        <div class="section-title">Rincian per Ukuran</div>
        <table class="items" style="margin-top:4px;">
            <thead><tr><th style="width:5%">No</th><th style="width:25%">SKU</th><th>Ukuran / Warna</th><th class="r" style="width:15%">Jumlah</th></tr></thead>
            <tbody>
                @foreach ($spk->items as $i => $item)
                    <tr><td class="c">{{ $i + 1 }}</td><td>{{ $item->variant->sku }}</td><td>{{ $item->variant->size }}{{ $item->variant->color ? ' / '.$item->variant->color : '' }}</td><td class="r">{{ qty($item->quantity) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @php $planned = $order->materials->filter(fn ($m) => (float) $m->planned_quantity > 0); @endphp
    @if ($planned->isNotEmpty())
        <div class="section-title">Bahan Titipan dari Perusahaan (Rencana)</div>
        <table class="items" style="margin-top:4px;">
            <thead><tr><th style="width:5%">No</th><th style="width:18%">Kode</th><th>Bahan</th><th class="r" style="width:15%">Jumlah</th><th style="width:10%">Satuan</th><th class="r" style="width:16%">Standar / pcs</th></tr></thead>
            <tbody>
                @foreach ($planned->values() as $i => $line)
                    @php $spkLine = $spk->materials->firstWhere('material_id', $line->material_id); @endphp
                    <tr>
                        <td class="c">{{ $i + 1 }}</td>
                        <td>{{ $line->material->code }}</td>
                        <td>{{ $line->material->name }}{{ $line->material->color ? ' ('.$line->material->color.')' : '' }}</td>
                        <td class="r bold">{{ qty($line->planned_quantity) }}</td>
                        <td>{{ $line->material->unit?->code }}</td>
                        <td class="r">{{ $spkLine && (float) $spk->quantity > 0 ? number_format((float) $spkLine->required_quantity / (float) $spk->quantity, 3, ',', '.') : '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="small muted" style="margin-top:3px;">Bahan tetap milik perusahaan. Sisa bahan wajib dikembalikan; pemakaian di atas standar dapat diperhitungkan sebagai potongan ongkos.</div>
    @endif

    @if ($order->notes)
        <div class="notes"><span class="bold">Instruksi:</span> {!! nl2br(e($order->notes)) !!}</div>
    @endif

    @include('pdf.partials.signatures', [
        'place' => trim(($company['city'] ? $company['city'].', ' : '').tanggal($order->order_date)),
        'signatures' => [['Dibuat oleh (Produksi),', $order->creator?->name], ['Disetujui,', null], ['Vendor Maklon,', $order->supplier->contact_person]],
    ])
@endsection
