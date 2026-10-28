@extends('pdf.layout')
@section('title', 'DAFTAR AMBIL BARANG')
@section('number', 'Marketplace · '.tanggal(today()))

@section('content')
    <table class="info">
        <tr>
            <td style="width:55%; padding-right:12px;">
                <table class="kv">
                    <tr><td class="k">Toko</td><td>: {{ $store?->display_name ?? 'Semua toko' }}</td></tr>
                    <tr><td class="k">Pesanan siap dikirim</td><td>: <span class="bold">{{ $orders->count() }}</span> pesanan</td></tr>
                    <tr><td class="k">Total barang</td><td>: <span class="bold">{{ qty($lines->sum('quantity')) }}</span> pcs</td></tr>
                </table>
            </td>
            <td class="muted small">Hanya pesanan Perlu Dikirim yang semua barangnya sudah kebagian stok gudang toko (urut batas kirim terdekat).</td>
        </tr>
    </table>

    <table class="items">
        <thead><tr><th style="width:5%">No</th><th style="width:16%">Gudang</th><th style="width:20%">SKU</th><th>Nama Barang</th><th class="r" style="width:10%">Pesanan</th><th class="r" style="width:10%">Ambil</th><th style="width:8%">Cek</th></tr></thead>
        <tbody>
            @forelse ($lines as $i => $line)
                <tr>
                    <td class="c">{{ $i + 1 }}</td>
                    <td>{{ $line['warehouse']->name }}</td>
                    <td>{{ $line['variant']->sku }}</td>
                    <td>{{ $line['variant']->display_name }}</td>
                    <td class="r">{{ $line['orders'] }}</td>
                    <td class="r bold">{{ qty($line['quantity']) }}</td>
                    <td></td>
                </tr>
            @empty
                <tr><td colspan="7" class="c muted">Tidak ada pesanan yang siap dikirim.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($orders->isNotEmpty())
        <table class="items small">
            <thead><tr><th style="width:22%">No. Pesanan</th><th style="width:16%">Toko</th><th style="width:12%">Batas Kirim</th><th>Barang</th><th style="width:18%">Kurir / Resi</th></tr></thead>
            <tbody>
                @foreach ($orders as $order)
                    <tr>
                        <td>{{ $order->order_number }}@if ($order->is_preorder) <span class="bold">(PO)</span>@endif</td>
                        <td>{{ $order->store->display_name }}</td>
                        <td>{{ $order->ship_by ? tanggal($order->ship_by) : '-' }}</td>
                        <td>{{ $order->items->map(fn ($i) => $i->variant->sku.' × '.qty($i->stockQuantity()))->implode(', ') }}</td>
                        <td>{{ trim($order->courier.' '.$order->tracking_number) ?: '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @include('pdf.partials.signatures', [
        'place' => trim(($company['city'] ? $company['city'].', ' : '').tanggal(today())),
        'signatures' => [['Disiapkan (Gudang),', null, 'warehouse'], ['Diperiksa (Packing),', null]],
    ])
@endsection
