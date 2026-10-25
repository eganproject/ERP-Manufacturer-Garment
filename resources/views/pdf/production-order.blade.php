@extends('pdf.layout')
@section('title', 'SURAT PERINTAH KERJA')
@section('number', $order->number)

@section('content')
    <table class="info">
        <tr>
            <td style="width:55%; padding-right:10px;">
                <div class="box">
                    <div class="label">Produk</div>
                    <div class="bold">{{ $order->product->code }} - {{ $order->product->name }}</div>
                    <div>BOM: {{ $order->bom->name }}</div>
                    @if ($order->salesOrder)
                        <div class="muted">Untuk SO {{ $order->salesOrder->number }} · {{ $order->salesOrder->customer->name }}</div>
                    @endif
                </div>
            </td>
            <td>
                <table class="kv">
                    <tr><td class="k">Tanggal Mulai</td><td>: {{ tanggal($order->start_date) }}</td></tr>
                    <tr><td class="k">Target Selesai</td><td>: {{ tanggal($order->due_date) }}</td></tr>
                    <tr><td class="k">Jumlah Rencana</td><td>: <span class="bold">{{ qty($order->quantity) }} {{ $order->product->unit?->code }}</span></td></tr>
                    @if (\App\Models\Warehouse::isMulti())<tr><td class="k">Gudang</td><td>: {{ $order->warehouse?->name }}</td></tr>@endif
                    <tr><td class="k">Status</td><td>: {{ $order->status->label() }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <div class="section-title">Rincian per Ukuran / Warna</div>
    <table class="items" style="margin-top:0">
        <thead><tr><th style="width:5%">No</th><th style="width:25%">SKU</th><th>Ukuran / Warna</th><th class="r" style="width:15%">Rencana</th><th class="r" style="width:15%">Jadi</th></tr></thead>
        <tbody>
            @foreach ($order->items as $i => $item)
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $item->variant->sku }}</td>
                    <td>{{ $item->variant->size }}{{ $item->variant->color ? ' / '.$item->variant->color : '' }}</td>
                    <td class="r bold">{{ qty($item->quantity) }}</td>
                    <td class="r">{{ (float) $item->finished_quantity > 0 ? qty($item->finished_quantity) : '' }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot><tr><td colspan="3" class="r">TOTAL</td><td class="r">{{ qty($order->quantity) }}</td><td class="r">{{ (float) $order->finished_quantity > 0 ? qty($order->finished_quantity) : '' }}</td></tr></tfoot>
    </table>

    <div class="section-title">Kebutuhan Bahan (dari BOM)</div>
    <table class="items" style="margin-top:0">
        <thead><tr><th style="width:5%">No</th><th style="width:20%">Kode</th><th>Bahan</th><th class="r" style="width:14%">Kebutuhan</th><th class="r" style="width:14%">Dikeluarkan</th><th style="width:9%">Satuan</th></tr></thead>
        <tbody>
            @foreach ($order->materials as $i => $line)
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $line->material->code }}</td>
                    <td>{{ $line->material->name }}{{ $line->material->color ? " ({$line->material->color})" : "" }}</td>
                    <td class="r">{{ qty($line->required_quantity) }}</td>
                    <td class="r">{{ (float) $line->issued_quantity > 0 ? qty($line->issued_quantity) : '' }}</td>
                    <td>{{ $line->material->unit->code }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="section-title">Lembar Kontrol Tahapan Produksi</div>
    <table class="items" style="margin-top:0">
        <thead><tr><th style="width:5%">No</th><th style="width:16%">Tahap</th><th style="width:13%">Tgl Mulai</th><th style="width:13%">Tgl Selesai</th><th class="r" style="width:10%">Output</th><th class="r" style="width:10%">Reject</th><th>PIC / Operator</th><th style="width:10%">Paraf</th></tr></thead>
        <tbody>
            @foreach ($order->stages as $stage)
                <tr style="height:24px">
                    <td class="c">{{ $stage->sequence }}</td>
                    <td class="bold">{{ $stage->stage->label() }}</td>
                    <td>{{ $stage->started_at ? tanggal($stage->started_at) : '' }}</td>
                    <td>{{ $stage->finished_at ? tanggal($stage->finished_at) : '' }}</td>
                    <td class="r">{{ (float) $stage->output_quantity > 0 ? qty($stage->output_quantity) : '' }}</td>
                    <td class="r">{{ (float) $stage->reject_quantity > 0 ? qty($stage->reject_quantity) : '' }}</td>
                    <td>{{ $stage->pic }}</td>
                    <td></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($order->notes)
        <div class="notes"><span class="bold">Catatan:</span> {{ $order->notes }}</div>
    @endif

    @include('pdf.partials.signatures', [
        'place' => trim(($company['city'] ? $company['city'].', ' : '').tanggal($order->start_date)),
        'signatures' => [['Dibuat oleh (PPIC),', $order->creator?->name], ['Kepala Produksi,', null], ['Gudang,', null]],
    ])
@endsection
