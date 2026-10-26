@extends('pdf.layout')
@section('title', 'BUKTI KASBON')
@section('number', $advance->number)

@section('content')
    @if ($advance->status === \App\Enums\ReturnStatus::Cancelled)
        <div class="stamp stamp-red">DIBATALKAN</div>
    @endif

    <table class="kv" style="font-size:10.5px;">
        <tr><td class="k" style="width:28%">Diberikan kepada</td><td>: <span class="bold">{{ $advance->employee->name }}</span> <span class="muted">({{ $advance->employee->code }})</span></td></tr>
        <tr><td class="k">Uang sejumlah</td><td>: <span class="bold">{{ rupiah($advance->amount) }}</span></td></tr>
        <tr><td class="k">Terbilang</td><td>: <i>{{ ucfirst(terbilang($advance->amount)) }} rupiah</i></td></tr>
        <tr><td class="k">Tanggal</td><td>: {{ tanggal($advance->date) }}</td></tr>
        <tr><td class="k">Metode</td><td>: {{ $advance->payment_method->label() }}</td></tr>
        @if ($advance->notes)<tr><td class="k">Keperluan</td><td>: {{ $advance->notes }}</td></tr>@endif
    </table>

    <div class="terms">Dengan menandatangani bukti ini, karyawan menyatakan telah menerima uang kasbon di atas dan bersedia dipotong dari upah borongan pada penggajian berikutnya sampai lunas.</div>

    @include('pdf.partials.signatures', [
        'place' => trim(($company['city'] ? $company['city'].', ' : '').tanggal($advance->date)),
        'signatures' => [['Diserahkan oleh,', $advance->creator?->name, 'finance'], ['Disetujui,', null, 'director'], ['Penerima,', $advance->employee->name]],
    ])
@endsection
