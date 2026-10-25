@php
    $type = $transaction->type;
    $isIn = $type === \App\Enums\CashTransactionType::In;
    $isTransfer = $type === \App\Enums\CashTransactionType::Transfer;
@endphp
@extends('pdf.layout')
@section('title', $isTransfer ? 'BUKTI TRANSFER ANTAR REKENING' : ($isIn ? 'BUKTI KAS MASUK' : 'BUKTI KAS KELUAR'))
@section('number', $transaction->number)

@section('content')
    @if ($transaction->isCancelled())
        <div style="text-align:center; color:#dc2626; font-weight:bold; font-size:14px; border:2px solid #dc2626; padding:4px; margin-bottom:8px;">DIBATALKAN</div>
    @endif

    <table class="kv" style="font-size:11px;">
        <tr><td class="k" style="width:28%">Tanggal</td><td>: {{ tanggal($transaction->date) }}</td></tr>
        @if ($isTransfer)
            <tr><td class="k">Dari rekening</td><td>: {{ $transaction->cashAccount->display_name }}</td></tr>
            <tr><td class="k">Ke rekening</td><td>: {{ $transaction->toAccount->display_name }}</td></tr>
        @else
            <tr><td class="k">{{ $isIn ? 'Masuk ke' : 'Keluar dari' }}</td><td>: {{ $transaction->cashAccount->display_name }}</td></tr>
            @if ($transaction->party_name)
                <tr><td class="k">{{ $isIn ? 'Diterima dari' : 'Dibayarkan kepada' }}</td><td>: <span class="bold">{{ $transaction->party_name }}</span></td></tr>
            @endif
        @endif
        <tr><td class="k">Uang sejumlah</td><td>: <span class="bold">{{ rupiah($transaction->amount) }}</span></td></tr>
        <tr><td class="k">Terbilang</td><td>: <i>{{ ucfirst(terbilang($transaction->amount)) }} rupiah</i></td></tr>
        <tr><td class="k">Keterangan</td><td>: {{ $transaction->description }}@if ($transaction->reference) · Ref. {{ $transaction->reference }}@endif</td></tr>
    </table>

    @if ($transaction->lines->isNotEmpty())
        <table class="items">
            <thead><tr><th style="width:5%">No</th><th style="width:15%">Kode Akun</th><th>Nama Akun</th><th>Keterangan</th><th class="r">Jumlah</th></tr></thead>
            <tbody>
                @foreach ($transaction->lines as $i => $line)
                    <tr>
                        <td class="c">{{ $i + 1 }}</td>
                        <td>{{ $line->account->code }}</td>
                        <td>{{ $line->account->name }}</td>
                        <td>{{ $line->description }}</td>
                        <td class="r">{{ rupiah($line->amount) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot><tr><td colspan="4" class="r">TOTAL</td><td class="r">{{ rupiah($transaction->amount) }}</td></tr></tfoot>
        </table>
    @endif

    @include('pdf.partials.signatures', [
        'place' => trim(($company['city'] ? $company['city'].', ' : '').tanggal($transaction->date)),
        'signatures' => $isIn
            ? [['Penyetor,', $transaction->party_name], ['Diterima oleh,', $transaction->creator?->name], ['Mengetahui,', null]]
            : [['Dibuat oleh,', $transaction->creator?->name], ['Disetujui oleh,', null], [$isTransfer ? 'Mengetahui,' : 'Penerima,', $isTransfer ? null : $transaction->party_name]],
    ])
@endsection
