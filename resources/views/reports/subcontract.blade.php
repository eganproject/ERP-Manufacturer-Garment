@use('App\Services\Reports\SubcontractReport')
@php
    $pct = fn ($v) => number_format((float) $v, 1, ',', '.').'%';
    $isBalance = $view === 'balance';
@endphp
<x-layouts.app title="Laporan Maklon">
    <x-card class="mb-6">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div class="w-52"><x-form.select name="view" label="Laporan" :options="SubcontractReport::VIEWS" :value="$view" :placeholder="false" onchange="this.form.submit()" /></div>
            @unless ($isBalance)
                <div class="w-40"><x-form.input name="from" label="Order Dari" :value="$from" data-date /></div>
                <div class="w-40"><x-form.input name="to" label="Sampai" :value="$to" data-date /></div>
            @endunless
            <div class="w-64"><x-form.select name="supplier_id" label="Vendor Maklon" :options="$vendors" :value="$supplierId" placeholder="Semua vendor" data-select /></div>
            <button type="submit" class="btn-secondary">Tampilkan</button>
        </form>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
            @unless ($isBalance)<x-report.period-presets />@else<span></span>@endunless
            <x-report.export-buttons />
        </div>
        <p class="mt-2 text-xs text-gray-500">
            @if ($isBalance)
                Bahan titipan pada order maklon yang masih berjalan, per {{ tanggal(today()) }}. Saldo = dikirim − diretur. Pemakaian standar = kebutuhan BOM per pcs × hasil yang sudah diterima (bagus + reject); "seharusnya sisa" adalah bahan yang wajib dikembalikan / dipertanggungjawabkan vendor.
            @else
                Order maklon dengan tanggal order pada periode. % reject = reject ÷ (bagus + reject). Susut = pemakaian aktual di atas standar BOM pada order yang sudah ditutup.
            @endif
        </p>
    </x-card>

    @if ($isBalance)
        <x-summary-tiles :tiles="[
            ['Vendor memegang bahan', $totals['vendors'].' vendor'],
            ['Order maklon berjalan', $totals['orders'].' order'],
            ['Nilai bahan di vendor', $withCost ? rupiah($totals['value']) : '-'],
        ]" />

        <x-card title="Saldo Bahan per Vendor" :padding="false">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Vendor / Order</th><th>Bahan</th>
                            <th class="text-right">Dikirim</th><th class="text-right">Diretur</th><th class="text-right">Saldo di Vendor</th>
                            <th class="text-right">Pemakaian Standar</th><th class="text-right">Seharusnya Sisa</th>
                            @if ($withCost)<th class="text-right">Nilai Saldo</th>@endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rows as $row)
                            <tr>
                                <td>
                                    <span class="font-medium">{{ $row['vendor'] }}</span>
                                    <div class="text-xs"><a href="{{ route('subcontract-orders.show', $row['order_id']) }}" class="text-indigo-600 hover:underline">{{ $row['order'] }}</a> · {{ $row['spk'] }}</div>
                                </td>
                                <td>{{ $row['material'] }}<div class="text-xs text-gray-500">{{ $row['code'] }}</div></td>
                                <td class="text-right whitespace-nowrap">{{ qty($row['sent']) }} <span class="text-xs text-gray-500">{{ $row['unit'] }}</span></td>
                                <td class="text-right">{{ qty($row['returned']) }}</td>
                                <td class="text-right font-semibold">{{ qty($row['balance']) }}</td>
                                <td class="text-right">{{ qty($row['standard_usage']) }}</td>
                                <td @class(['text-right', 'font-medium text-amber-600' => $row['expected_left'] > 0])>{{ qty($row['expected_left']) }}</td>
                                @if ($withCost)<td class="text-right whitespace-nowrap">{{ rupiah($row['value'], false) }}</td>@endif
                            </tr>
                        @empty
                            <x-empty-row :colspan="$withCost ? 8 : 7" message="Tidak ada bahan yang sedang dipegang vendor maklon." />
                        @endforelse
                    </tbody>
                    @if ($withCost && $rows->isNotEmpty())
                        <tfoot class="bg-gray-50 font-semibold">
                            <tr><td colspan="7" class="px-4 py-3">Total</td><td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($totals['value'], false) }}</td></tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </x-card>
    @else
        <div class="mb-6 grid grid-cols-2 gap-4 xl:grid-cols-4">
            @foreach ([
                ['Order maklon', $totals['count'], qty($totals['quantity']).' pcs dipesan', null],
                ['Hasil bagus', qty($totals['received']).' pcs', 'Reject '.qty($totals['reject']).' pcs ('.$pct($totals['reject_rate']).')', $totals['reject_rate'] > 5 ? 'red' : null],
                ['Ongkos jasa', rupiah($totals['service_cost']), 'Sudah ditagih '.rupiah($totals['billed']), null],
                ['Nilai susut bahan', $withCost ? rupiah($totals['shrinkage']) : '-', 'Di atas pemakaian standar', $withCost && $totals['shrinkage'] > 0 ? 'amber' : null],
            ] as [$label, $value, $hint, $color])
                <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <p class="text-sm text-gray-500">{{ $label }}</p>
                    <p @class(['mt-1 text-xl font-semibold', 'text-gray-900' => ! $color, 'text-red-600' => $color === 'red', 'text-amber-600' => $color === 'amber'])>{{ $value }}</p>
                    <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
                </div>
            @endforeach
        </div>

        <x-card title="Rekap per Vendor" :padding="false">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Vendor</th><th class="text-right">Order</th><th class="text-right">Qty Order</th><th class="text-right">Bagus</th><th class="text-right">Reject</th>
                            <th class="text-right">Ongkos Jasa</th><th class="text-right">Sudah Ditagih</th>
                            @if ($withCost)<th class="text-right">Nilai Susut</th>@endif
                            <th class="text-right">Tepat Waktu</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rows as $row)
                            <tr>
                                <td><a href="{{ route('subcontract-orders.index', ['supplier_id' => $row['key']]) }}" class="font-medium text-indigo-600 hover:underline">{{ $row['name'] }}</a><div class="text-xs text-gray-500">{{ $row['code'] }}@if ($row['open']) · {{ $row['open'] }} berjalan @endif</div></td>
                                <td class="text-right">{{ $row['count'] }}</td>
                                <td class="text-right">{{ qty($row['quantity']) }}</td>
                                <td class="text-right">{{ qty($row['received']) }}</td>
                                <td @class(['text-right whitespace-nowrap', 'text-red-600 font-medium' => $row['reject_rate'] > 5])>{{ qty($row['reject']) }} <span class="text-xs">({{ $pct($row['reject_rate']) }})</span></td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($row['service_cost'], false) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($row['billed'], false) }}</td>
                                @if ($withCost)<td @class(['text-right whitespace-nowrap', 'text-amber-600' => $row['shrinkage'] > 0])>{{ rupiah($row['shrinkage'], false) }}</td>@endif
                                <td class="text-right">{{ $pct($row['on_time']) }}</td>
                            </tr>
                        @empty
                            <x-empty-row :colspan="$withCost ? 9 : 8" message="Belum ada order maklon pada periode ini." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
    @endif
</x-layouts.app>
