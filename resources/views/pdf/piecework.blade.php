@php $spk = $sheet->productionOrder; @endphp
@extends('pdf.layout')
@section('title', 'HASIL KERJA BORONGAN')
@section('number', $sheet->number)

@section('content')
    <table class="info">
        <tr>
            <td style="width:55%; padding-right:12px;">
                <div class="box">
                    <div class="label">SPK</div>
                    <div class="bold">{{ $spk->number }}</div>
                    <div>{{ $spk->product->name }}</div>
                </div>
            </td>
            <td>
                <table class="kv">
                    <tr><td class="k">Tanggal Kerja</td><td>: {{ tanggal($sheet->date) }}</td></tr>
                    <tr><td class="k">Jumlah Karyawan</td><td>: {{ $sheet->entries->pluck('employee_id')->unique()->count() }} orang</td></tr>
                    <tr><td class="k">Total Upah</td><td>: <span class="bold">{{ rupiah($sheet->total_amount) }}</span></td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead><tr><th style="width:5%">No</th><th>Karyawan</th><th>Operasi</th><th class="r" style="width:9%">Bagus</th><th class="r" style="width:9%">Reject</th><th class="r" style="width:13%">Tarif</th><th class="r" style="width:15%">Upah</th></tr></thead>
        <tbody>
            @foreach ($sheet->entries as $i => $entry)
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $entry->employee->name }}<div class="muted small">{{ $entry->employee->code }}</div></td>
                    <td>{{ $entry->operation_name }}</td>
                    <td class="r">{{ qty($entry->quantity) }}</td>
                    <td class="r">{{ qty($entry->reject_quantity) }}</td>
                    <td class="r">{{ rupiah($entry->rate, false) }}</td>
                    <td class="r">{{ rupiah($entry->amount, false) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="3" class="r">TOTAL</td><td class="r">{{ qty($sheet->entries->sum('quantity')) }}</td><td class="r">{{ qty($sheet->entries->sum('reject_quantity')) }}</td><td></td><td class="r">{{ rupiah($sheet->total_amount, false) }}</td></tr>
        </tfoot>
    </table>
    <div class="notes small muted">Upah dihitung dari hasil bagus × tarif; hasil reject QC tidak dibayar.</div>

    @if ($sheet->notes)
        <div class="notes"><span class="bold">Catatan:</span> {{ $sheet->notes }}</div>
    @endif

    @include('pdf.partials.signatures', [
        'place' => trim(($company['city'] ? $company['city'].', ' : '').tanggal($sheet->date)),
        'signatures' => [['Dicatat oleh,', $sheet->creator?->name], ['Diperiksa (QC),', null], ['Disetujui,', null, 'production']],
    ])
@endsection
