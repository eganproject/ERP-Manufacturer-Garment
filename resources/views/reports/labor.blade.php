@use('App\Services\Reports\LaborReport')
@php
    $pct = fn ($v) => number_format((float) $v, 1, ',', '.').'%';
    $isOrders = $view === 'orders';
@endphp
<x-layouts.app title="Laporan Upah Borongan">
    <x-card class="mb-6">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div class="w-44"><x-form.select name="view" label="Laporan" :options="LaborReport::VIEWS" :value="$view" :placeholder="false" onchange="this.form.submit()" /></div>
            <div class="w-40"><x-form.input name="from" label="Tanggal Kerja Dari" :value="$from" data-date /></div>
            <div class="w-40"><x-form.input name="to" label="Sampai" :value="$to" data-date /></div>
            <div class="w-64"><x-form.select name="employee_id" label="Karyawan" :options="$employees" :value="$employeeId" placeholder="Semua karyawan" data-select /></div>
            <button type="submit" class="btn-secondary">Tampilkan</button>
        </form>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
            <x-report.period-presets />
            <x-report.export-buttons />
        </div>
        <p class="mt-2 text-xs text-gray-500">Berdasarkan tanggal hasil kerja. Upah = hasil bagus × tarif saat dicatat; reject QC tidak dibayar. "Sudah dibayar" = masuk penggajian berstatus Dibayar.</p>
    </x-card>

    <div class="mb-6 grid grid-cols-2 gap-4 xl:grid-cols-4">
        @foreach ([
            ['Karyawan bekerja', $totals['employees'].' orang', $totals['orders'].' SPK', null],
            ['Hasil bagus', qty($totals['good']).' pcs/operasi', 'Reject '.qty($totals['reject']).' ('.$pct($totals['reject_rate']).')', $totals['reject_rate'] > 5 ? 'red' : null],
            ['Total upah borongan', rupiah($totals['amount']), 'Masuk HPP SPK', null],
            ['Belum dibayar', rupiah($totals['unpaid']), 'Sudah dibayar '.rupiah($totals['paid']), $totals['unpaid'] > 0 ? 'amber' : null],
        ] as [$label, $value, $hint, $color])
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-sm text-gray-500">{{ $label }}</p>
                <p @class(['mt-1 text-xl font-semibold', 'text-gray-900' => ! $color, 'text-red-600' => $color === 'red', 'text-amber-600' => $color === 'amber'])>{{ $value }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
            </div>
        @endforeach
    </div>

    <x-card :title="LaborReport::VIEWS[$view]" :padding="false">
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead>
                    @if ($isOrders)
                        <tr><th>SPK</th><th class="text-right">Qty SPK</th><th class="text-right">Karyawan</th><th class="text-right">Bagus</th><th class="text-right">Reject</th><th class="text-right">Upah</th><th class="text-right">Upah / pcs</th><th class="text-right">Belum Dibayar</th></tr>
                    @else
                        <tr><th>Karyawan</th><th class="text-right">Hari Kerja</th><th class="text-right">Bagus</th><th class="text-right">Reject</th><th class="text-right">Upah</th><th class="text-right">Sudah Dibayar</th><th class="text-right">Belum Dibayar</th></tr>
                    @endif
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($rows as $row)
                        @if ($isOrders)
                            <tr>
                                <td><a href="{{ route('production-orders.show', $row['key']) }}" class="font-medium text-indigo-600 hover:underline">{{ $row['number'] }}</a><div class="text-xs text-gray-500">{{ $row['product'] }}</div></td>
                                <td class="text-right">{{ qty($row['quantity']) }}</td>
                                <td class="text-right">{{ $row['employees'] }}</td>
                                <td class="text-right">{{ qty($row['good']) }}</td>
                                <td class="text-right">{{ qty($row['reject']) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($row['amount'], false) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($row['per_pcs'], false) }}</td>
                                <td @class(['text-right whitespace-nowrap', 'text-amber-600' => $row['unpaid'] > 0])>{{ rupiah($row['unpaid'], false) }}</td>
                            </tr>
                        @else
                            <tr>
                                <td><a href="{{ route('employees.show', $row['key']) }}" class="font-medium text-indigo-600 hover:underline">{{ $row['name'] }}</a><div class="text-xs text-gray-500">{{ $row['code'] }} · {{ $row['type'] }}</div></td>
                                <td class="text-right">{{ $row['days'] }}</td>
                                <td class="text-right">{{ qty($row['good']) }}</td>
                                <td @class(['text-right whitespace-nowrap', 'text-red-600 font-medium' => $row['reject_rate'] > 5])>{{ qty($row['reject']) }} <span class="text-xs">({{ $pct($row['reject_rate']) }})</span></td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($row['amount'], false) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($row['paid'], false) }}</td>
                                <td @class(['text-right whitespace-nowrap', 'text-amber-600' => $row['unpaid'] > 0])>{{ rupiah($row['unpaid'], false) }}</td>
                            </tr>
                        @endif
                    @empty
                        <x-empty-row :colspan="$isOrders ? 8 : 7" message="Belum ada hasil kerja pada periode ini." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</x-layouts.app>
