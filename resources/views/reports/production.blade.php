@php
    $pct = fn ($v) => number_format((float) $v, 1, ',', '.').'%';
@endphp
<x-layouts.app title="Laporan Hasil Produksi">
    <x-card class="mb-6">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div class="w-40"><x-form.input name="from" label="Selesai Dari" :value="$from" data-date /></div>
            <div class="w-40"><x-form.input name="to" label="Sampai" :value="$to" data-date /></div>
            <div class="w-72"><x-form.select name="product_id" label="Produk" :options="$productOptions" :value="$productId" placeholder="Semua produk" data-select /></div>
            <input type="hidden" name="detail" value="{{ $detail }}">
            <button type="submit" class="btn-secondary">Tampilkan</button>
            @if (request()->hasAny(['from', 'to', 'product_id']))
                <a href="{{ route('reports.production') }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>
            @endif
        </form>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
            <x-report.period-presets />
            <x-report.export-buttons />
        </div>
        <p class="mt-2 text-xs text-gray-500">SPK berstatus selesai dengan tanggal selesai pada periode. % reject = reject ÷ (jadi + reject).</p>
    </x-card>

    <div class="mb-6 grid grid-cols-2 gap-4 xl:grid-cols-5">
        @foreach ([
            ['SPK selesai', $totals['count'], 'Rata-rata '.number_format($totals['lead_days'], 1, ',', '.').' hari pengerjaan', null],
            ['Barang jadi', qty($totals['finished']).' pcs', 'Rencana '.qty($totals['planned']).' pcs', null],
            ['Pencapaian', $pct($totals['achievement']), 'Jadi ÷ rencana', $totals['achievement'] < 95 && $totals['count'] ? 'amber' : null],
            ['Reject', qty($totals['reject']).' pcs', $pct($totals['reject_rate']).' dari total output', $totals['reject_rate'] > 5 ? 'red' : null],
            ['Tepat waktu', $pct($totals['on_time']), 'Selesai ≤ tanggal target', $totals['on_time'] < 80 && $totals['count'] ? 'amber' : null],
        ] as [$label, $value, $hint, $color])
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-sm text-gray-500">{{ $label }}</p>
                <p @class(['mt-1 text-xl font-semibold', 'text-gray-900' => ! $color, 'text-red-600' => $color === 'red', 'text-amber-600' => $color === 'amber'])>{{ $value }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
            </div>
        @endforeach
    </div>

    <div class="mb-6 grid grid-cols-1 gap-6 xl:grid-cols-3">
        <x-card title="Reject per Tahap" :padding="false">
            <table class="table-base">
                <thead><tr><th>Tahap</th><th class="text-right">Output</th><th class="text-right">Reject</th><th class="text-right">% Reject</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @php $maxRate = max(1, (float) $stages->max('reject_rate')); @endphp
                    @forelse ($stages as $row)
                        <tr>
                            <td>{{ $row['name'] }}</td>
                            <td class="text-right">{{ qty($row['output']) }}</td>
                            <td @class(['text-right', 'text-red-600 font-medium' => $row['reject'] > 0])>{{ qty($row['reject']) }}</td>
                            <td @class(['text-right whitespace-nowrap', 'font-semibold text-red-600' => $row['reject_rate'] > 0 && $row['reject_rate'] >= $maxRate])>{{ $pct($row['reject_rate']) }}</td>
                        </tr>
                    @empty
                        <x-empty-row colspan="4" message="Belum ada data tahap." />
                    @endforelse
                </tbody>
            </table>
        </x-card>

        <x-card title="Rincian" :padding="false" class="xl:col-span-2">
            <x-slot:header>
                <div class="flex gap-1 rounded-lg bg-gray-100 p-0.5 text-sm">
                    @foreach (['products' => 'Per Produk', 'orders' => 'Per SPK'] as $value => $label)
                        <a href="{{ request()->fullUrlWithQuery(['detail' => $value, 'export' => null]) }}"
                           @class(['rounded-md px-3 py-1', 'bg-white font-medium text-gray-900 shadow-sm' => $detail === $value, 'text-gray-500 hover:text-gray-700' => $detail !== $value])>{{ $label }}</a>
                    @endforeach
                </div>
            </x-slot:header>
            <div class="overflow-x-auto">
                @if ($detail === 'orders')
                    <table class="table-base">
                        <thead><tr><th>No. SPK</th><th>Produk</th><th>Target</th><th>Selesai</th><th class="text-right">Rencana</th><th class="text-right">Jadi</th><th class="text-right">Reject</th><th class="text-right">% Reject</th></tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($orders as $order)
                                <tr>
                                    <td><a href="{{ route('production-orders.show', $order) }}" class="font-medium text-indigo-600 hover:underline">{{ $order->number }}</a></td>
                                    <td>{{ $order->product->name }}</td>
                                    <td class="whitespace-nowrap">{{ tanggal($order->due_date) }}</td>
                                    <td class="whitespace-nowrap">
                                        {{ tanggal($order->completed_at) }}
                                        @unless ($order->on_time)<div class="text-xs text-red-600">Terlambat</div>@endunless
                                    </td>
                                    <td class="text-right">{{ qty($order->quantity) }}</td>
                                    <td class="text-right font-medium">{{ qty($order->finished_quantity) }}</td>
                                    <td @class(['text-right', 'text-red-600' => (float) $order->reject_quantity > 0])>{{ qty($order->reject_quantity) }}</td>
                                    <td class="text-right">{{ $pct($order->reject_rate) }}</td>
                                </tr>
                            @empty
                                <x-empty-row colspan="8" message="Belum ada SPK yang selesai pada periode ini." />
                            @endforelse
                        </tbody>
                    </table>
                @else
                    <table class="table-base">
                        <thead><tr><th>Produk</th><th class="text-right">SPK</th><th class="text-right">Rencana</th><th class="text-right">Jadi</th><th class="text-right">Reject</th><th class="text-right">% Reject</th><th class="text-right">Pencapaian</th><th class="text-right">HPP / pcs</th></tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($products as $row)
                                <tr>
                                    <td><span class="font-medium">{{ $row['name'] }}</span><div class="text-xs text-gray-500">{{ $row['code'] }}</div></td>
                                    <td class="text-right">{{ $row['count'] }}</td>
                                    <td class="text-right">{{ qty($row['planned']) }}</td>
                                    <td class="text-right font-medium">{{ qty($row['finished']) }}</td>
                                    <td @class(['text-right', 'text-red-600' => $row['reject'] > 0])>{{ qty($row['reject']) }}</td>
                                    <td @class(['text-right', 'text-red-600 font-medium' => $row['reject_rate'] > 5])>{{ $pct($row['reject_rate']) }}</td>
                                    <td class="text-right">{{ $pct($row['achievement']) }}</td>
                                    <td class="text-right whitespace-nowrap">{{ $row['unit_cost'] !== null ? rupiah($row['unit_cost'], false) : '-' }}</td>
                                </tr>
                            @empty
                                <x-empty-row colspan="8" message="Belum ada SPK yang selesai pada periode ini." />
                            @endforelse
                        </tbody>
                    </table>
                @endif
            </div>
        </x-card>
    </div>
</x-layouts.app>
