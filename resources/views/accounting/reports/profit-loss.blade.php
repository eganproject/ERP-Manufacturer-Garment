@use('App\Enums\AccountType')
@php
    $ledgerLink = fn ($id) => route('reports.ledger', ['account_id' => $id, 'from' => $from->toDateString(), 'to' => $to->toDateString()]);
    $prevAmount = fn ($type, $id) => $previous['sections'][$type]['accounts']->firstWhere('id', $id)['amount'] ?? 0;
    $margin = fn ($value) => $net_revenue ? number_format($value / $net_revenue * 100, 1, ',', '.').'%' : '-';
@endphp
<x-layouts.app title="Laba Rugi">
    <x-card class="mb-6">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div class="w-40"><x-form.input name="from" label="Dari" :value="$from" data-date /></div>
            <div class="w-40"><x-form.input name="to" label="Sampai" :value="$to" data-date /></div>
            <button type="submit" class="btn-secondary">Tampilkan</button>
            <div class="ml-auto self-end"><x-report.export-buttons /></div>
        </form>
        <div class="mt-3"><x-report.period-presets /></div>
    </x-card>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-4">
        @foreach ([['Pendapatan bersih', $net_revenue, $previous['net_revenue']], ['Laba kotor', $gross_profit, $previous['gross_profit']], ['Laba usaha', $operating_profit, $previous['operating_profit']], ['Laba bersih', $net_profit, $previous['net_profit']]] as [$label, $value, $prev])
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-sm text-gray-500">{{ $label }}</p>
                <p @class(['mt-1 text-xl font-semibold', 'text-gray-900' => $value >= 0, 'text-red-600' => $value < 0])>{{ rupiah($value) }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ $label !== 'Pendapatan bersih' ? 'Margin '.$margin($value).' · ' : '' }}periode lalu {{ rupiah($prev, false) }}</p>
            </div>
        @endforeach
    </div>

    <x-card :title="'Laporan Laba Rugi · '.tanggal($from).' s.d. '.tanggal($to)" :padding="false">
        <table class="table-base">
            <thead><tr><th class="w-24">Kode</th><th>Keterangan</th><th class="text-right">Periode Ini</th><th class="text-right">Periode Lalu</th></tr></thead>
            <tbody class="divide-y divide-gray-100">
                @foreach ([
                    [AccountType::Revenue, null],
                    [AccountType::CostOfSales, ['LABA KOTOR', $gross_profit, $previous['gross_profit']]],
                    [AccountType::Expense, ['LABA USAHA', $operating_profit, $previous['operating_profit']]],
                    [AccountType::OtherIncome, null],
                    [AccountType::OtherExpense, ['LABA (RUGI) BERSIH', $net_profit, $previous['net_profit']]],
                ] as [$type, $subtotal])
                    @php $section = $sections[$type->value]; @endphp
                    <tr class="bg-gray-50"><td colspan="4" class="font-semibold text-gray-800">{{ strtoupper($section['label']) }}</td></tr>
                    @forelse ($section['accounts'] as $row)
                        <tr>
                            <td class="font-mono text-sm text-gray-500">{{ $row['code'] }}</td>
                            <td class="pl-8"><a href="{{ $ledgerLink($row['id']) }}" class="hover:text-indigo-600 hover:underline">{{ $row['name'] }}</a></td>
                            <td @class(['text-right whitespace-nowrap', 'text-red-600' => $row['amount'] < 0])>{{ rupiah($row['amount'], false) }}</td>
                            <td class="text-right whitespace-nowrap text-gray-500">{{ rupiah($prevAmount($type->value, $row['id']), false) }}</td>
                        </tr>
                    @empty
                        <tr><td></td><td class="pl-8 text-sm text-gray-400" colspan="3">Tidak ada</td></tr>
                    @endforelse
                    <tr>
                        <td></td>
                        <td class="font-medium">Total {{ $section['label'] }}</td>
                        <td class="text-right font-medium whitespace-nowrap">{{ rupiah($section['total'], false) }}</td>
                        <td class="text-right whitespace-nowrap text-gray-500">{{ rupiah($previous['sections'][$type->value]['total'], false) }}</td>
                    </tr>
                    @if ($subtotal)
                        <tr class="bg-indigo-50/60">
                            <td></td>
                            <td class="font-bold text-gray-900">{{ $subtotal[0] }}</td>
                            <td @class(['text-right font-bold whitespace-nowrap', 'text-red-600' => $subtotal[1] < 0])>{{ rupiah($subtotal[1], false) }}</td>
                            <td class="text-right font-medium whitespace-nowrap text-gray-500">{{ rupiah($subtotal[2], false) }}</td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    </x-card>
    <p class="mt-3 text-xs text-gray-500">Periode lalu = rentang dengan jumlah hari yang sama tepat sebelum periode ini. HPP mencakup harga pokok barang terjual, selisih harga pembelian/produksi, selisih stock opname, serta selisih overhead pabrik (biaya overhead aktual − overhead dibebankan ke produksi).</p>
</x-layouts.app>
