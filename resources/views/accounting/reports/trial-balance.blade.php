<x-layouts.app title="Neraca Saldo">
    <x-card class="mb-6">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div class="w-40"><x-form.input name="from" label="Dari" :value="$from" data-date /></div>
            <div class="w-40"><x-form.input name="to" label="Sampai" :value="$to" data-date /></div>
            <label class="mb-2 inline-flex items-center gap-2 text-sm text-gray-600">
                <input type="checkbox" name="include_zero" value="1" class="rounded border-gray-300 text-indigo-600" @checked(request()->boolean('include_zero'))>
                Tampilkan akun bersaldo 0
            </label>
            <button type="submit" class="btn-secondary">Tampilkan</button>
            <div class="ml-auto self-end"><x-report.export-buttons /></div>
        </form>
        <div class="mt-3"><x-report.period-presets /></div>
    </x-card>

    @php $balanced = abs($totals['closing_debit'] - $totals['closing_credit']) < 0.01; @endphp
    <div @class(['mb-4 rounded-lg px-4 py-3 text-sm', 'bg-emerald-50 text-emerald-800' => $balanced, 'bg-red-50 text-red-800' => ! $balanced])>
        {{ $balanced ? 'Neraca saldo seimbang: total debit = total kredit.' : 'Neraca saldo tidak seimbang, selisih '.rupiah(abs($totals['closing_debit'] - $totals['closing_credit'])).'.' }}
        Saldo awal akun laba rugi dihitung sejak 1 Januari; laba rugi tahun sebelumnya sudah masuk Laba Ditahan.
    </div>

    <x-card :padding="false">
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead>
                    <tr><th rowspan="2">Kode</th><th rowspan="2">Akun</th><th colspan="2" class="text-center">Saldo Awal</th><th colspan="2" class="text-center">Mutasi</th><th colspan="2" class="text-center">Saldo Akhir</th></tr>
                    <tr><th class="text-right">Debit</th><th class="text-right">Kredit</th><th class="text-right">Debit</th><th class="text-right">Kredit</th><th class="text-right">Debit</th><th class="text-right">Kredit</th></tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($rows as $row)
                        <tr>
                            <td class="font-mono text-sm">{{ $row['code'] }}</td>
                            <td><a href="{{ route('reports.ledger', ['account_id' => $row['id'], 'from' => $from->toDateString(), 'to' => $to->toDateString()]) }}" class="text-indigo-600 hover:underline">{{ $row['name'] }}</a></td>
                            @foreach (['opening_debit', 'opening_credit', 'debit', 'credit', 'closing_debit', 'closing_credit'] as $key)
                                <td @class(['text-right whitespace-nowrap', 'font-medium' => str_starts_with($key, 'closing')])>{{ $row[$key] ? rupiah($row[$key], false) : '' }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <x-empty-row colspan="8" message="Belum ada jurnal." />
                    @endforelse
                </tbody>
                <tfoot class="bg-gray-50 font-semibold">
                    <tr>
                        <td class="px-4 py-3" colspan="2">Total</td>
                        @foreach (['opening_debit', 'opening_credit', 'debit', 'credit', 'closing_debit', 'closing_credit'] as $key)
                            <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($totals[$key], false) }}</td>
                        @endforeach
                    </tr>
                </tfoot>
            </table>
        </div>
    </x-card>
</x-layouts.app>
