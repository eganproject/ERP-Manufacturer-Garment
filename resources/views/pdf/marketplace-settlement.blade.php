@extends('pdf.layout')
@section('title', 'PENCAIRAN MARKETPLACE')
@section('number', $settlement->number)

@section('content')
    @if ($settlement->status === \App\Enums\MarketplaceSettlementStatus::Cancelled)
        <div class="stamp stamp-red">DIBATALKAN</div>
    @elseif ($settlement->isDraft())
        <div class="stamp stamp-amber">DRAFT – BELUM DIPOSTING</div>
    @endif

    <table class="info">
        <tr>
            <td style="width:50%; padding-right:12px;">
                <table class="kv">
                    <tr><td class="k">Toko</td><td>: <span class="bold">{{ $settlement->store->display_name }}</span></td></tr>
                    <tr><td class="k">Tanggal pencairan</td><td>: {{ tanggal($settlement->date) }}</td></tr>
                    <tr><td class="k">Masuk ke</td><td>: {{ $settlement->store->balanceAccountName() }}</td></tr>
                    @if ($settlement->isPosted())<tr><td class="k">Diposting</td><td>: {{ $settlement->poster?->name }} · {{ $settlement->posted_at?->translatedFormat('d M Y H:i') }}</td></tr>@endif
                </table>
            </td>
            <td>
                <table class="kv">
                    <tr><td class="k">Nilai pesanan</td><td class="r">{{ rupiah($settlement->order_amount) }}</td></tr>
                    <tr><td class="k">Voucher</td><td class="r">{{ rupiah($settlement->voucher) }}</td></tr>
                    <tr><td class="k">Biaya platform</td><td class="r">{{ rupiah($settlement->fees) }}</td></tr>
                    <tr><td class="k">Ongkir penjual</td><td class="r">{{ rupiah($settlement->shipping_fee) }}</td></tr>
                    <tr><td class="k">Penyesuaian</td><td class="r">{{ rupiah($settlement->adjustment) }}</td></tr>
                    <tr><td class="k bold" style="border-top:1px solid #d4d4d8; padding-top:3px;">Dana bersih</td><td class="r bold" style="border-top:1px solid #d4d4d8; padding-top:3px;">{{ rupiah($settlement->net_amount) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>

    <table class="items small">
        <thead><tr><th>Pesanan</th><th class="r">Nilai</th><th class="r">Voucher</th><th class="r">Komisi</th><th class="r">Layanan</th><th class="r">Transaksi</th><th class="r">Ongkir</th><th class="r">Lain</th><th class="r">Penyesuaian</th><th class="r">Dana</th></tr></thead>
        <tbody>
            @foreach ($settlement->lines as $line)
                <tr>
                    <td>{{ $line->order_number ?? 'Bukan pesanan' }}@if ($line->description)<div class="muted">{{ $line->description }}</div>@endif</td>
                    <td class="r">{{ rupiah($line->order_amount, false) }}</td>
                    <td class="r">{{ rupiah($line->voucher, false) }}</td>
                    <td class="r">{{ rupiah($line->commission, false) }}</td>
                    <td class="r">{{ rupiah($line->service_fee, false) }}</td>
                    <td class="r">{{ rupiah($line->transaction_fee, false) }}</td>
                    <td class="r">{{ rupiah($line->shipping_fee, false) }}</td>
                    <td class="r">{{ rupiah($line->other_fee, false) }}</td>
                    <td class="r">{{ rupiah($line->adjustment, false) }}</td>
                    <td class="r bold">{{ rupiah($line->net_amount, false) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr><td>TOTAL</td><td class="r">{{ rupiah($settlement->lines->sum('order_amount'), false) }}</td><td class="r">{{ rupiah($settlement->lines->sum('voucher'), false) }}</td><td class="r">{{ rupiah($settlement->lines->sum('commission'), false) }}</td><td class="r">{{ rupiah($settlement->lines->sum('service_fee'), false) }}</td><td class="r">{{ rupiah($settlement->lines->sum('transaction_fee'), false) }}</td><td class="r">{{ rupiah($settlement->lines->sum('shipping_fee'), false) }}</td><td class="r">{{ rupiah($settlement->lines->sum('other_fee'), false) }}</td><td class="r">{{ rupiah($settlement->lines->sum('adjustment'), false) }}</td><td class="r">{{ rupiah($settlement->lines->sum('net_amount'), false) }}</td></tr>
        </tfoot>
    </table>

    @if ($settlement->notes)
        <div class="notes"><span class="bold">Catatan:</span> {{ $settlement->notes }}</div>
    @endif

    @include('pdf.partials.signatures', [
        'place' => trim(($company['city'] ? $company['city'].', ' : '').tanggal($settlement->date)),
        'signatures' => [['Disusun oleh,', $settlement->creator?->name], ['Diperiksa,', null, 'finance'], ['Disetujui,', null, 'director']],
    ])
@endsection
