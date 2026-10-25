@php
    $isAr = $kind === 'receivables';
    $route = $isAr ? 'reports.receivables-aging' : 'reports.payables-aging';
    $docRoute = $isAr ? 'invoices.show' : 'bills.show';
    $partyLabel = $isAr ? 'Pelanggan' : 'Supplier';
@endphp
<x-layouts.app :title="$isAr ? 'Umur Piutang' : 'Umur Hutang'">
    <x-card :padding="false" class="mb-6">
        <div class="border-b border-gray-200 p-4">
            <form method="GET" class="flex flex-wrap items-end gap-2">
                <div class="w-44"><x-form.input name="as_of" label="Per Tanggal" :value="$asOf" data-date /></div>
                <div class="w-64"><x-form.select name="party_id" :label="$partyLabel" :options="$parties" :value="$partyId" :placeholder="'Semua '.strtolower($partyLabel)" data-select /></div>
                <button type="submit" class="btn-secondary">Tampilkan</button>
                @if (request()->hasAny(['as_of', 'party_id']))
                    <a href="{{ route($route) }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>
                @endif
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead>
                    <tr>
                        <th>{{ $partyLabel }}</th>
                        <th class="text-right">Dok.</th>
                        @foreach ($buckets as $label)<th class="text-right">{{ $label }}</th>@endforeach
                        <th class="text-right">Total</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($rows as $row)
                        <tr>
                            <td>
                                <a href="{{ route($route, ['party_id' => $row['party']->id, 'as_of' => $asOf->format('Y-m-d')]) }}" class="font-medium text-indigo-600 hover:underline">{{ $row['party']->name }}</a>
                                <div class="text-xs text-gray-500">Termin {{ $row['party']->payment_terms }} hari</div>
                            </td>
                            <td class="text-right">{{ $row['count'] }}</td>
                            @foreach ($buckets as $key => $label)
                                <td @class(['text-right whitespace-nowrap', 'text-gray-300' => $row[$key] <= 0, 'text-red-600 font-medium' => $key !== 'current' && $row[$key] > 0])>{{ rupiah($row[$key], false) }}</td>
                            @endforeach
                            <td class="text-right font-semibold whitespace-nowrap">{{ rupiah($row['total'], false) }}</td>
                        </tr>
                    @empty
                        <x-empty-row :colspan="count($buckets) + 3" :message="$isAr ? 'Tidak ada piutang pada tanggal ini.' : 'Tidak ada hutang pada tanggal ini.'" />
                    @endforelse
                </tbody>
                @if ($rows->isNotEmpty())
                    <tfoot class="bg-gray-50 font-semibold">
                        <tr>
                            <td class="px-4 py-3">Total</td>
                            <td class="px-4 py-3 text-right">{{ $rows->sum('count') }}</td>
                            @foreach ($buckets as $key => $label)<td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($totals[$key], false) }}</td>@endforeach
                            <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($totals['total'], false) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </x-card>

    <x-card :title="'Rincian '.($isAr ? 'Invoice' : 'Tagihan').' Belum Lunas'" :padding="false">
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>No. Dokumen</th><th>{{ $partyLabel }}</th><th>Tanggal</th><th>Jatuh Tempo</th><th class="text-right">Umur (hari lewat)</th><th class="text-right">Total</th><th class="text-right">Saldo</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($documents as $doc)
                        <tr>
                            <td><a href="{{ route($docRoute, $doc) }}" class="font-medium text-indigo-600 hover:underline">{{ $doc->number }}</a></td>
                            <td>{{ $doc->party()->name }}</td>
                            <td>{{ tanggal($isAr ? $doc->invoice_date : $doc->bill_date) }}</td>
                            <td>{{ tanggal($doc->due_date) }}</td>
                            <td @class(['text-right', 'text-red-600 font-medium' => $doc->age > 0])>{{ $doc->age > 0 ? $doc->age : '-' }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($doc->total) }}</td>
                            <td class="text-right whitespace-nowrap font-medium">{{ rupiah($doc->balance) }}</td>
                        </tr>
                    @empty
                        <x-empty-row colspan="7" />
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</x-layouts.app>
