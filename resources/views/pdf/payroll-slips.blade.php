@extends('pdf.layout')
@section('title', 'SLIP UPAH BORONGAN')
@section('number', $payroll->number)

@section('content')
    @foreach ($lines as $index => $line)
        @if ($index > 0)
            <div style="page-break-before: always;"></div>
            <table class="kop">
                <tr>
                    <td><div class="name">{{ $company['name'] }}</div></td>
                    <td class="doc"><div class="title">SLIP UPAH BORONGAN</div><div class="number">{{ $payroll->number }}</div></td>
                </tr>
            </table>
            <div class="rule"></div>
        @endif

        <table class="info">
            <tr>
                <td style="width:55%; padding-right:10px;">
                    <div class="box">
                        <div class="label">Karyawan</div>
                        <div class="bold">{{ $line->employee->name }}</div>
                        <div class="muted">{{ $line->employee->code }} · {{ $line->employee->work_type->label() }}</div>
                        @if ($line->employee->bank_account)<div class="small">{{ $line->employee->bank_account }}</div>@endif
                    </div>
                </td>
                <td>
                    <table class="kv">
                        <tr><td class="k">Periode</td><td>: {{ $payroll->periodLabel() }}</td></tr>
                        <tr><td class="k">Status</td><td>: {{ $payroll->status->label() }}</td></tr>
                        @if ($payroll->payment_date)<tr><td class="k">Tanggal Bayar</td><td>: {{ tanggal($payroll->payment_date) }}</td></tr>@endif
                    </table>
                </td>
            </tr>
        </table>

        <div class="section-title">Rincian Hasil Kerja</div>
        <table class="items" style="margin-top:2px;">
            <thead><tr><th style="width:13%">Tanggal</th><th style="width:18%">SPK</th><th>Operasi</th><th class="r" style="width:9%">Bagus</th><th class="r" style="width:9%">Reject</th><th class="r" style="width:12%">Tarif</th><th class="r" style="width:14%">Upah</th></tr></thead>
            <tbody>
                @foreach ($line->entries->sortBy(fn ($e) => $e->sheet->date->toDateString().'|'.$e->id) as $entry)
                    <tr>
                        <td>{{ tanggal($entry->sheet->date) }}</td>
                        <td>{{ $entry->sheet->productionOrder->number }}<div class="muted small">{{ $entry->sheet->productionOrder->product->name }}</div></td>
                        <td>{{ $entry->operation_name }}</td>
                        <td class="r">{{ qty($entry->quantity) }}</td>
                        <td class="r">{{ qty($entry->reject_quantity) }}</td>
                        <td class="r">{{ rupiah($entry->rate, false) }}</td>
                        <td class="r">{{ rupiah($entry->amount, false) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr><td colspan="3">Total hasil kerja</td><td class="r">{{ qty($line->entries->sum('quantity')) }}</td><td class="r">{{ qty($line->entries->sum('reject_quantity')) }}</td><td></td><td class="r">{{ rupiah($line->gross, false) }}</td></tr>
            </tfoot>
        </table>

        <table style="margin-top:10px;">
            <tr>
                <td style="width:50%;"></td>
                <td>
                    <table class="kv">
                        <tr><td class="k">Upah borongan</td><td class="r">{{ rupiah($line->gross) }}</td></tr>
                        <tr><td class="k">Bonus / tunjangan</td><td class="r">{{ rupiah($line->allowance) }}</td></tr>
                        <tr><td class="k">Potongan kasbon</td><td class="r">({{ rupiah($line->advance_deduction) }})</td></tr>
                        <tr><td class="k">Potongan lain{{ $line->notes ? ' – '.$line->notes : '' }}</td><td class="r">({{ rupiah($line->other_deduction) }})</td></tr>
                        <tr><td class="k bold" style="border-top:1px solid #9ca3af; padding-top:3px;">Diterima</td><td class="r bold" style="border-top:1px solid #9ca3af; padding-top:3px; font-size:12px;">{{ rupiah($line->net) }}</td></tr>
                    </table>
                </td>
            </tr>
        </table>
        <div class="terbilang">Terbilang: {{ terbilang($line->net) }} rupiah</div>

        @include('pdf.partials.signatures', [
            'place' => trim(($company['city'] ? $company['city'].', ' : '').tanggal($payroll->payment_date ?? today())),
            'signatures' => [['Dibayar,', null], ['Diterima,', $line->employee->name]],
        ])
    @endforeach
@endsection
