@extends('pdf.layout')
@section('title', 'DAFTAR UPAH BORONGAN')
@section('number', $payroll->number)

@section('content')
    <table class="kv" style="width:60%;">
        <tr><td class="k">Periode</td><td>: {{ $payroll->periodLabel() }}</td></tr>
        <tr><td class="k">Status</td><td>: {{ $payroll->status->label() }}@if ($payroll->payment_date) · dibayar {{ tanggal($payroll->payment_date) }} ({{ $payroll->payment_method?->label() }})@endif</td></tr>
    </table>

    <table class="items">
        <thead>
            <tr>
                <th style="width:4%">No</th><th>Karyawan</th><th class="r" style="width:9%">Pcs/Operasi</th><th class="r" style="width:11%">Upah</th>
                <th class="r" style="width:10%">Bonus</th><th class="r" style="width:10%">Kasbon</th><th class="r" style="width:10%">Pot. Lain</th>
                <th class="r" style="width:12%">Diterima</th><th style="width:14%">Tanda Tangan</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($payroll->lines as $i => $line)
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td><span class="bold">{{ $line->employee->name }}</span> <span class="muted small">{{ $line->employee->code }}</span>@if ($line->notes)<div class="muted small">{{ $line->notes }}</div>@endif</td>
                    <td class="r">{{ qty($line->quantity) }}</td>
                    <td class="r">{{ rupiah($line->gross, false) }}</td>
                    <td class="r">{{ rupiah($line->allowance, false) }}</td>
                    <td class="r">{{ rupiah($line->advance_deduction, false) }}</td>
                    <td class="r">{{ rupiah($line->other_deduction, false) }}</td>
                    <td class="r bold">{{ rupiah($line->net, false) }}</td>
                    <td style="height:22px;"></td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td colspan="2">Total ({{ $payroll->lines->count() }} karyawan)</td>
                <td class="r">{{ qty($payroll->lines->sum('quantity')) }}</td>
                <td class="r">{{ rupiah($payroll->total_gross, false) }}</td>
                <td class="r">{{ rupiah($payroll->total_allowance, false) }}</td>
                <td class="r">{{ rupiah($payroll->lines->sum('advance_deduction'), false) }}</td>
                <td class="r">{{ rupiah($payroll->lines->sum('other_deduction'), false) }}</td>
                <td class="r">{{ rupiah($payroll->total_net, false) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
    <div class="terbilang">Terbilang: {{ terbilang($payroll->total_net) }} rupiah</div>

    @include('pdf.partials.signatures', [
        'place' => trim(($company['city'] ? $company['city'].', ' : '').tanggal($payroll->payment_date ?? today())),
        'signatures' => [['Disusun,', $payroll->creator?->name], ['Disetujui,', $payroll->approver?->name], ['Dibayar,', null]],
    ])
@endsection
