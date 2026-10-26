@extends('pdf.layout')
@section('title', 'BUKTI JURNAL')
@section('number', $entry->number)

@section('content')
    @if ($entry->isReversed())
        <div class="stamp stamp-red">DIBALIK OLEH {{ $entry->reversal->number }}</div>
    @endif

    <table class="info">
        <tr>
            <td style="width:55%; padding-right:12px;">
                <div class="box">
                    <div class="label">Keterangan</div>
                    <div>{{ $entry->description }}</div>
                </div>
            </td>
            <td>
                <table class="kv">
                    <tr><td class="k">Tanggal</td><td>: {{ tanggal($entry->date) }}</td></tr>
                    <tr><td class="k">Jenis</td><td>: {{ $entry->type->label() }}</td></tr>
                    @if ($entry->source_number)<tr><td class="k">{{ $entry->sourceLabel() ?? 'Referensi' }}</td><td>: {{ $entry->source_number }}</td></tr>@endif
                    @if ($entry->reversalOf)<tr><td class="k">Membalik</td><td>: {{ $entry->reversalOf->number }}</td></tr>@endif
                </table>
            </td>
        </tr>
    </table>

    <table class="items">
        <thead><tr><th style="width:12%">Kode</th><th style="width:30%">Akun</th><th>Keterangan</th><th class="r" style="width:16%">Debit</th><th class="r" style="width:16%">Kredit</th></tr></thead>
        <tbody>
            @foreach ($entry->lines as $line)
                <tr>
                    <td>{{ $line->account->code }}</td>
                    <td @if ((float) $line->credit > 0) style="padding-left:18px;" @endif>{{ $line->account->name }}</td>
                    <td>{{ $line->description }}@if ($ref = $line->referenceLabel())<div class="muted small">{{ $ref }}</div>@endif</td>
                    <td class="r">{{ (float) $line->debit ? rupiah($line->debit, false) : '' }}</td>
                    <td class="r">{{ (float) $line->credit ? rupiah($line->credit, false) : '' }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td colspan="3" class="r">TOTAL</td><td class="r">{{ rupiah($entry->lines->sum('debit'), false) }}</td><td class="r">{{ rupiah($entry->lines->sum('credit'), false) }}</td></tr>
        </tfoot>
    </table>
    <div class="terbilang">Terbilang: {{ ucfirst(terbilang($entry->lines->sum('debit'))) }} rupiah</div>

    @include('pdf.partials.signatures', [
        'place' => trim(($company['city'] ? $company['city'].', ' : '').tanggal($entry->date)),
        'signatures' => [['Dibuat oleh,', $entry->creator?->name ?? 'Sistem'], ['Diperiksa,', null, 'finance'], ['Disetujui,', null, 'director']],
    ])
@endsection
