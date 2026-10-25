<x-layouts.app title="Buku Besar">
    <x-card class="mb-6">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div class="w-80"><x-form.select name="account_id" label="Akun" :options="$options" :value="$account?->id" :placeholder="false" data-select /></div>
            <div class="w-40"><x-form.input name="from" label="Dari" :value="$from" data-date /></div>
            <div class="w-40"><x-form.input name="to" label="Sampai" :value="$to" data-date /></div>
            <button type="submit" class="btn-secondary">Tampilkan</button>
            <div class="ml-auto self-end"><x-report.export-buttons /></div>
        </form>
        <div class="mt-3"><x-report.period-presets /></div>
    </x-card>

    @if ($account)
        <x-summary-tiles :tiles="[
            ['Saldo awal '.tanggal($from), rupiah($data['opening'])],
            ['Mutasi debit / kredit', rupiah($data['debit'], false).' / '.rupiah($data['credit'], false)],
            ['Saldo akhir '.tanggal($to), rupiah($data['closing']), $data['closing'] < 0 ? 'red' : null],
        ]" />

        <x-card :title="$account->display_name.' · saldo normal '.$account->normal_balance" :padding="false">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>Tanggal</th><th>No. Jurnal</th><th>Dokumen</th><th>Keterangan</th><th class="text-right">Debit</th><th class="text-right">Kredit</th><th class="text-right">Saldo</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <tr class="bg-gray-50">
                            <td colspan="6" class="font-medium">Saldo awal</td>
                            <td class="text-right font-medium whitespace-nowrap">{{ rupiah($data['opening'], false) }}</td>
                        </tr>
                        @forelse ($data['lines'] as $line)
                            <tr>
                                <td class="whitespace-nowrap">{{ tanggal($line['date']) }}</td>
                                <td><a href="{{ route('journals.show', $line['entry']) }}" class="text-indigo-600 hover:underline">{{ $line['number'] }}</a></td>
                                <td class="text-sm">{{ $line['source_number'] }}</td>
                                <td class="text-sm">{{ $line['description'] }}@if ($line['reference'])<div class="text-xs text-gray-500">{{ $line['reference'] }}</div>@endif</td>
                                <td class="text-right whitespace-nowrap">{{ $line['debit'] ? rupiah($line['debit'], false) : '' }}</td>
                                <td class="text-right whitespace-nowrap">{{ $line['credit'] ? rupiah($line['credit'], false) : '' }}</td>
                                <td @class(['text-right whitespace-nowrap', 'text-red-600' => $line['balance'] < 0])>{{ rupiah($line['balance'], false) }}</td>
                            </tr>
                        @empty
                            <x-empty-row colspan="7" message="Tidak ada mutasi pada periode ini." />
                        @endforelse
                    </tbody>
                    <tfoot class="bg-gray-50 font-semibold">
                        <tr>
                            <td class="px-4 py-3" colspan="4">Total mutasi & saldo akhir</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($data['debit'], false) }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($data['credit'], false) }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($data['closing'], false) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-card>
    @endif
</x-layouts.app>
