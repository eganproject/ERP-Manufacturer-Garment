@php
    $monthNames = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $pct = fn ($actual, $budget) => $budget != 0 ? number_format($actual / $budget * 100, 1, ',', '.').'%' : '-';
    $ledgerLink = fn ($id) => route('reports.ledger', ['account_id' => $id, 'from' => $from->toDateString(), 'to' => $to->toDateString()]);
@endphp
<x-layouts.app :title="$budget->name.' · '.$budget->year">
    <x-slot:actions>
        <a href="{{ route('budgets.index') }}" class="btn-secondary">Daftar Anggaran</a>
        @can('budget.manage')
            <a href="{{ route('budgets.create', ['copy' => $budget->id, 'year' => $budget->year + 1]) }}" class="btn-secondary">Salin ke {{ $budget->year + 1 }}</a>
            <a href="{{ route('budgets.edit', $budget) }}" class="btn-primary">Ubah Anggaran</a>
        @endcan
    </x-slot:actions>

    <x-card class="mb-6">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div class="w-40"><x-form.select name="from_month" label="Dari bulan" :options="$monthNames" :value="$fromMonth" :placeholder="false" /></div>
            <div class="w-40"><x-form.select name="to_month" label="Sampai bulan" :options="$monthNames" :value="$toMonth" :placeholder="false" /></div>
            <button type="submit" class="btn-secondary">Tampilkan</button>
            <div class="ml-auto self-end"><x-report.export-buttons /></div>
        </form>
        <div class="mt-3 flex flex-wrap items-center gap-1 text-xs">
            <span class="text-gray-500">Cepat:</span>
            @foreach (['Setahun' => [1, 12], 'Q1' => [1, 3], 'Q2' => [4, 6], 'Q3' => [7, 9], 'Q4' => [10, 12]] + ($budget->year == today()->year ? ['s.d. bulan ini' => [1, today()->month], 'Bulan ini' => [today()->month, today()->month]] : []) as $label => [$a, $b])
                <a href="{{ request()->fullUrlWithQuery(['from_month' => $a, 'to_month' => $b, 'export' => null]) }}" class="rounded-full border border-gray-200 px-2.5 py-0.5 text-gray-600 hover:border-indigo-300 hover:text-indigo-600">{{ $label }}</a>
            @endforeach
        </div>
    </x-card>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
        @foreach ([
            ['Pendapatan', $groups['revenue'] ?? null, true],
            ['HPP + Beban usaha', isset($groups['cost_of_sales']) || isset($groups['expense']) ? ['budget' => ($groups['cost_of_sales']['budget'] ?? 0) + ($groups['expense']['budget'] ?? 0), 'actual' => ($groups['cost_of_sales']['actual'] ?? 0) + ($groups['expense']['actual'] ?? 0)] : null, false],
            ['Laba bersih', $profit, true],
        ] as [$label, $values, $higherIsGood])
            @php
                $b = $values['budget'] ?? 0;
                $a = $values['actual'] ?? 0;
                $good = abs($a - $b) < 0.005 ? null : (($a > $b) === $higherIsGood);
                $ratio = $b > 0 ? min(100, max(0, $a / $b * 100)) : 0;
            @endphp
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-sm text-gray-500">{{ $label }}</p>
                <p class="mt-1 text-xl font-semibold text-gray-900">{{ rupiah($a) }}</p>
                <p class="mt-1 text-xs text-gray-500">anggaran {{ rupiah($b, false) }} · <span @class(['font-medium', 'text-emerald-600' => $good === true, 'text-red-600' => $good === false])>{{ $pct($a, $b) }}</span></p>
                <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-100"><div @class(['h-full rounded-full', 'bg-emerald-500' => $good !== false, 'bg-red-500' => $good === false]) style="width: {{ $ratio }}%"></div></div>
            </div>
        @endforeach
    </div>

    <x-card :title="'Anggaran vs Realisasi · '.tanggal($from).' s.d. '.tanggal($to)" :padding="false" class="mb-6">
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th class="w-24">Kode</th><th>Akun</th><th class="text-right">Anggaran</th><th class="text-right">Realisasi</th><th class="text-right">Selisih</th><th class="w-48">Pemakaian</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($groups as $group)
                        <tr class="bg-gray-50"><td colspan="6" class="font-semibold text-gray-800">{{ strtoupper($group['label']) }}</td></tr>
                        @foreach ($group['rows'] as $row)
                            <tr>
                                <td class="font-mono text-sm text-gray-500">{{ $row['code'] }}</td>
                                <td class="pl-8">
                                    @can('accounting.view')
                                        <a href="{{ $ledgerLink($row['id']) }}" class="hover:text-indigo-600 hover:underline">{{ $row['name'] }}</a>
                                    @else
                                        {{ $row['name'] }}
                                    @endcan
                                    @if ($row['budget'] == 0 && $row['actual'] != 0)<x-badge color="amber">Di luar anggaran</x-badge>@endif
                                </td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($row['budget'], false) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($row['actual'], false) }}</td>
                                <td @class(['text-right whitespace-nowrap', 'text-emerald-600' => $row['favorable'] === true, 'text-red-600' => $row['favorable'] === false])>{{ $row['variance'] > 0 ? '+' : '' }}{{ rupiah($row['variance'], false) }}</td>
                                <td>
                                    @if ($row['percent'] !== null)
                                        <div class="flex items-center gap-2">
                                            <div class="h-1.5 flex-1 overflow-hidden rounded-full bg-gray-100"><div @class(['h-full rounded-full', 'bg-emerald-500' => $row['favorable'] !== false, 'bg-red-500' => $row['favorable'] === false]) style="width: {{ min(100, max(0, $row['percent'])) }}%"></div></div>
                                            <span class="w-14 text-right text-xs text-gray-600">{{ number_format($row['percent'], 1, ',', '.') }}%</span>
                                        </div>
                                    @else
                                        <span class="text-xs text-gray-400">-</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        <tr>
                            <td></td>
                            <td class="font-medium">Total {{ $group['label'] }}</td>
                            <td class="text-right font-medium whitespace-nowrap">{{ rupiah($group['budget'], false) }}</td>
                            <td class="text-right font-medium whitespace-nowrap">{{ rupiah($group['actual'], false) }}</td>
                            <td class="text-right font-medium whitespace-nowrap">{{ $group['variance'] > 0 ? '+' : '' }}{{ rupiah($group['variance'], false) }}</td>
                            <td class="text-xs text-gray-600">{{ $pct($group['actual'], $group['budget']) }}</td>
                        </tr>
                    @empty
                        <x-empty-row compact colspan="6" message="Belum ada akun anggaran." />
                    @endforelse
                </tbody>
                <tfoot class="font-semibold">
                    <tr class="bg-indigo-50/60">
                        <td></td>
                        <td class="px-4 py-3">LABA (RUGI) BERSIH</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($profit['budget'], false) }}</td>
                        <td @class(['px-4 py-3 text-right whitespace-nowrap', 'text-red-600' => $profit['actual'] < 0])>{{ rupiah($profit['actual'], false) }}</td>
                        <td @class(['px-4 py-3 text-right whitespace-nowrap', 'text-emerald-600' => $profit['variance'] > 0, 'text-red-600' => $profit['variance'] < 0])>{{ $profit['variance'] > 0 ? '+' : '' }}{{ rupiah($profit['variance'], false) }}</td>
                        <td class="px-4 py-3 text-xs">{{ $pct($profit['actual'], $profit['budget']) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </x-card>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Per Bulan" :padding="false" class="lg:col-span-2">
            <div class="overflow-x-auto">
                <table class="table-base text-sm">
                    <thead><tr><th>Bulan</th><th class="text-right">Pendapatan Anggaran</th><th class="text-right">Pendapatan Realisasi</th><th class="text-right">Laba Anggaran</th><th class="text-right">Laba Realisasi</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($months as $m => $row)
                            <tr @class(['bg-indigo-50/40' => $m >= $fromMonth && $m <= $toMonth])>
                                <td>{{ $monthNames[$m] }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($row['budget_revenue'], false) }}</td>
                                <td @class(['text-right whitespace-nowrap', 'text-gray-400' => $row['actual_revenue'] === null, 'text-emerald-600' => $row['actual_revenue'] !== null && $row['actual_revenue'] >= $row['budget_revenue'] && $row['budget_revenue'] > 0, 'text-red-600' => $row['actual_revenue'] !== null && $row['actual_revenue'] < $row['budget_revenue']])>{{ $row['actual_revenue'] === null ? '–' : rupiah($row['actual_revenue'], false) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($row['budget_profit'], false) }}</td>
                                <td @class(['text-right whitespace-nowrap', 'text-gray-400' => $row['actual_profit'] === null, 'text-red-600' => $row['actual_profit'] !== null && $row['actual_profit'] < 0])>{{ $row['actual_profit'] === null ? '–' : rupiah($row['actual_profit'], false) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>
        <div class="space-y-6">
            <x-card title="Keterangan">
                <dl class="space-y-2 text-sm">
                    <div><dt class="text-gray-500">Tahun</dt><dd>{{ $budget->year }}</dd></div>
                    @if ($budget->description)<div><dt class="text-gray-500">Catatan</dt><dd>{{ $budget->description }}</dd></div>@endif
                    <div><dt class="text-gray-500">Disusun oleh</dt><dd>{{ $budget->creator?->name ?? '-' }}</dd></div>
                </dl>
                <p class="mt-4 text-xs text-gray-500">Realisasi diambil dari jurnal (sama dengan laporan laba rugi). Hijau = menguntungkan (pendapatan di atas target / beban di bawah batas), merah = sebaliknya. Akun laba rugi yang tidak dianggarkan tetap ditampilkan.</p>
            </x-card>
            <x-activity-log :activities="$budget->activities" />
        </div>
    </div>
</x-layouts.app>
