@php
    $ledgerLink = fn ($id) => route('reports.ledger', ['account_id' => $id, 'from' => $from->toDateString(), 'to' => $to->toDateString()]);
@endphp
<x-layouts.app title="Laporan Arus Kas">
    <x-card class="mb-6">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div class="w-40"><x-form.input name="from" label="Dari" :value="$from" data-date /></div>
            <div class="w-40"><x-form.input name="to" label="Sampai" :value="$to" data-date /></div>
            <div class="w-52"><x-form.select name="method" label="Metode" :options="['direct' => 'Langsung', 'indirect' => 'Tidak langsung']" :value="$method" :placeholder="false" /></div>
            <button type="submit" class="btn-secondary">Tampilkan</button>
            <div class="ml-auto self-end"><x-report.export-buttons /></div>
        </form>
        <div class="mt-3"><x-report.period-presets /></div>
    </x-card>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Saldo kas & bank awal', $opening],
            [$sections['operating']['label'], $sections['operating']['total']],
            ['Investasi + pendanaan', $sections['investing']['total'] + $sections['financing']['total']],
            ['Saldo kas & bank akhir', $closing],
        ] as [$label, $value])
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-sm text-gray-500">{{ $label }}</p>
                <p @class(['mt-1 text-xl font-semibold', 'text-gray-900' => $value >= 0, 'text-red-600' => $value < 0])>{{ rupiah($value) }}</p>
            </div>
        @endforeach
    </div>

    @unless ($balanced)
        <div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">
            Arus kas ({{ rupiah($computed_change) }}) tidak sama dengan perubahan saldo kas ({{ rupiah($net_change) }}). Periksa apakah ada rekening kas/bank yang belum ditandai di Bagan Akun.
        </div>
    @endunless

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <x-card :title="'Arus Kas Metode '.($method === 'indirect' ? 'Tidak Langsung' : 'Langsung').' · '.tanggal($from).' s.d. '.tanggal($to)" :padding="false" class="xl:col-span-2">
            <table class="table-base">
                <thead><tr><th class="w-24">Kode</th><th>Keterangan</th><th class="text-right">Jumlah</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($sections as $key => $section)
                        <tr class="bg-gray-50"><td colspan="3" class="font-semibold text-gray-800">{{ strtoupper($section['label']) }}</td></tr>
                        @forelse ($section['items'] as $item)
                            <tr>
                                <td class="font-mono text-sm text-gray-500">{{ $item['code'] }}</td>
                                <td class="pl-8">
                                    @if ($item['account_id'])
                                        <a href="{{ $ledgerLink($item['account_id']) }}" class="hover:text-indigo-600 hover:underline">{{ $item['name'] }}</a>
                                    @else
                                        <span class="font-medium">{{ $item['name'] }}</span>
                                    @endif
                                </td>
                                <td @class(['text-right whitespace-nowrap', 'text-red-600' => $item['amount'] < 0])>{{ rupiah($item['amount'], false) }}</td>
                            </tr>
                        @empty
                            <tr><td></td><td class="pl-8 text-sm text-gray-400" colspan="2">Tidak ada</td></tr>
                        @endforelse
                        <tr class="bg-indigo-50/60">
                            <td></td>
                            <td class="font-semibold">Kas bersih dari {{ strtolower($section['label']) }}</td>
                            <td @class(['text-right font-semibold whitespace-nowrap', 'text-red-600' => $section['total'] < 0])>{{ rupiah($section['total'], false) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="font-semibold">
                    <tr class="border-t-2 border-gray-300">
                        <td></td><td class="px-4 py-3">Kenaikan (penurunan) kas bersih</td>
                        <td @class(['px-4 py-3 text-right whitespace-nowrap', 'text-red-600' => $computed_change < 0])>{{ rupiah($computed_change, false) }}</td>
                    </tr>
                    <tr><td></td><td class="px-4 py-2 font-normal">Saldo kas & bank awal periode</td><td class="px-4 py-2 text-right font-normal whitespace-nowrap">{{ rupiah($opening, false) }}</td></tr>
                    <tr class="bg-gray-50"><td></td><td class="px-4 py-3">Saldo kas & bank akhir periode</td><td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($closing, false) }}</td></tr>
                </tfoot>
            </table>
        </x-card>

        <div class="space-y-6">
            <x-card title="Per Rekening" :padding="false">
                <table class="table-base">
                    <thead><tr><th>Rekening</th><th class="text-right">Awal</th><th class="text-right">Akhir</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($accounts as $row)
                            <tr>
                                <td class="text-sm">
                                    @can('cashbank.view')
                                        <a href="{{ route('cash-bank.statement', ['account' => $row['id'], 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}" class="hover:text-indigo-600 hover:underline">{{ $row['name'] }}</a>
                                    @else
                                        {{ $row['name'] }}
                                    @endcan
                                    <div class="text-xs text-gray-500"><span class="text-emerald-600">+{{ rupiah($row['inflow'], false) }}</span> / <span class="text-red-600">−{{ rupiah($row['outflow'], false) }}</span></div>
                                </td>
                                <td class="text-right text-sm whitespace-nowrap">{{ rupiah($row['opening'], false) }}</td>
                                <td class="text-right text-sm font-medium whitespace-nowrap">{{ rupiah($row['closing'], false) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
            <x-card title="Cara Membaca">
                <ul class="list-disc space-y-2 pl-4 text-sm text-gray-600">
                    @if ($method === 'indirect')
                        <li>Dimulai dari laba bersih, lalu disesuaikan dengan perubahan saldo akun neraca: kenaikan piutang/persediaan = kas keluar, kenaikan hutang = kas masuk.</li>
                    @else
                        <li>Setiap uang masuk/keluar rekening kas & bank dikelompokkan menurut akun lawannya (piutang, hutang, beban, aset tetap, pinjaman, modal).</li>
                        <li>Transfer antar rekening sendiri tidak dihitung.</li>
                    @endif
                    <li>Kelompok aktivitas mengikuti pengaturan akun di Bagan Akun (aset tetap = investasi, pinjaman jangka panjang & modal = pendanaan, lainnya = operasi).</li>
                </ul>
            </x-card>
        </div>
    </div>
</x-layouts.app>
