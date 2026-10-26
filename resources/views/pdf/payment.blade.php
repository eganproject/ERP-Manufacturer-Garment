@php
    $isReceipt = $payment->type === \App\Enums\PaymentType::Receipt;
    $party = $payment->party();
@endphp
@extends('pdf.layout')
@section('title', $isReceipt ? 'KWITANSI' : 'BUKTI PEMBAYARAN')
@section('number', $payment->number)

@section('content')
    @if ($payment->isCancelled())
        <div class="stamp stamp-red">DIBATALKAN</div>
    @endif

    <table class="kv" style="font-size:11px;">
        <tr><td class="k" style="width:28%">{{ $isReceipt ? 'Telah diterima dari' : 'Dibayarkan kepada' }}</td><td>: <span class="bold">{{ $party?->name }}</span></td></tr>
        <tr><td class="k">Uang sejumlah</td><td>: <span class="bold">{{ rupiah($payment->amount) }}</span></td></tr>
        <tr><td class="k">Terbilang</td><td>: <i>{{ ucfirst(terbilang($payment->amount)) }} rupiah</i></td></tr>
        <tr><td class="k">Untuk pembayaran</td><td>: {{ $isReceipt ? 'Invoice' : 'Tagihan' }} {{ $payment->allocations->map(fn ($a) => $a->payable->number)->implode(', ') }}</td></tr>
        <tr><td class="k">Tanggal</td><td>: {{ tanggal($payment->payment_date) }}</td></tr>
        <tr><td class="k">Metode</td><td>: {{ $payment->method->label() }}@if ($payment->reference) · Ref. {{ $payment->reference }}@endif</td></tr>
    </table>

    <table class="items">
        <thead><tr><th style="width:5%">No</th><th>No. {{ $isReceipt ? 'Invoice' : 'Tagihan' }}</th><th>Tanggal</th><th>Jatuh Tempo</th><th class="r">Total Dokumen</th><th class="r">Dibayar</th></tr></thead>
        <tbody>
            @foreach ($payment->allocations as $i => $allocation)
                @php $doc = $allocation->payable; @endphp
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $doc->number }}@if (! $isReceipt && $doc->supplier_invoice_number) <span class="muted">(Faktur {{ $doc->supplier_invoice_number }})</span>@endif</td>
                    <td>{{ tanggal($isReceipt ? $doc->invoice_date : $doc->bill_date) }}</td>
                    <td>{{ tanggal($doc->due_date) }}</td>
                    <td class="r">{{ rupiah($doc->total) }}</td>
                    <td class="r">{{ rupiah($allocation->amount) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot><tr><td colspan="5" class="r">TOTAL</td><td class="r">{{ rupiah($payment->amount) }}</td></tr></tfoot>
    </table>

    @if ($payment->notes)
        <div class="notes"><span class="bold">Catatan:</span> {{ $payment->notes }}</div>
    @endif

    @include('pdf.partials.signatures', [
        'place' => trim(($company['city'] ? $company['city'].', ' : '').tanggal($payment->payment_date)),
        'signatures' => $isReceipt
            ? [['Penyetor,', null], ['Diterima oleh,', $payment->creator?->name, 'finance']]
            : [['Dibuat oleh,', $payment->creator?->name], ['Disetujui oleh,', null, 'director'], ['Penerima,', null]],
    ])
@endsection
