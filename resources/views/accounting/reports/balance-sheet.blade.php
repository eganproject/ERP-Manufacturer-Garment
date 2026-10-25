<x-layouts.app title="Neraca">
    <x-card class="mb-6">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div class="w-44"><x-form.input name="date" label="Per tanggal" :value="$asOf" data-date /></div>
            <button type="submit" class="btn-secondary">Tampilkan</button>
            <div class="ml-auto self-end"><x-report.export-buttons /></div>
        </form>
    </x-card>

    <div @class(['mb-4 rounded-lg px-4 py-3 text-sm', 'bg-emerald-50 text-emerald-800' => $balanced, 'bg-red-50 text-red-800' => ! $balanced])>
        {{ $balanced ? 'Neraca seimbang: total aset = total kewajiban + ekuitas.' : 'Neraca tidak seimbang, selisih '.rupiah($difference).'.' }}
        Laba tahun berjalan dihitung dari 1 Januari {{ $asOf->year }} s.d. {{ tanggal($asOf) }}.
    </div>

    @php
        $ledgerLink = fn ($id) => route('reports.ledger', ['account_id' => $id, 'from' => $asOf->copy()->startOfYear()->toDateString(), 'to' => $asOf->toDateString()]);
    @endphp

    <div class="grid grid-cols-1 gap-6 xl:grid-cols-2">
        @foreach ([['asset'], ['liability', 'equity']] as $column)
            <div class="space-y-6">
                @foreach ($column as $key)
                    @php $s = $groups[$key]; @endphp
                    <x-card :title="$s['label']" :padding="false">
                        <table class="table-base">
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($s['groups'] as $group => $items)
                                    @if ($group !== '')
                                        <tr class="bg-gray-50"><td colspan="2" class="text-sm font-semibold text-gray-700">{{ $group }}</td></tr>
                                    @endif
                                    @foreach ($items as $item)
                                        <tr>
                                            <td @class(['pl-8' => $group !== ''])>
                                                @if ($item['id'])
                                                    <a href="{{ $ledgerLink($item['id']) }}" class="hover:text-indigo-600 hover:underline"><span class="font-mono text-xs text-gray-500">{{ $item['code'] }}</span> {{ $item['name'] }}</a>
                                                @else
                                                    <a href="{{ route('reports.profit-loss', ['from' => $asOf->copy()->startOfYear()->toDateString(), 'to' => $asOf->toDateString()]) }}" class="italic hover:text-indigo-600 hover:underline">{{ $item['name'] }}</a>
                                                @endif
                                            </td>
                                            <td @class(['text-right whitespace-nowrap', 'text-red-600' => $item['amount'] < 0])>{{ rupiah($item['amount'], false) }}</td>
                                        </tr>
                                    @endforeach
                                @empty
                                    <x-empty-row colspan="2" message="Tidak ada saldo." />
                                @endforelse
                            </tbody>
                            <tfoot class="bg-gray-50 font-semibold">
                                <tr><td class="px-4 py-3">Total {{ $s['label'] }}</td><td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($s['total'], false) }}</td></tr>
                            </tfoot>
                        </table>
                    </x-card>
                @endforeach
                @if (in_array('equity', $column, true))
                    <div class="rounded-xl border border-indigo-200 bg-indigo-50 px-5 py-3 text-sm font-semibold text-indigo-900 flex justify-between">
                        <span>Total Kewajiban + Ekuitas</span>
                        <span>{{ rupiah($groups['liability']['total'] + $groups['equity']['total'], false) }}</span>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</x-layouts.app>
