<x-layouts.app :title="'Mutasi '.$account->name">
    <x-slot:actions>
        <a href="{{ route('cash-bank.index') }}" class="btn-secondary">Kembali</a>
        @can('cashbank.manage')
            <a href="{{ route('cash-transactions.create', ['type' => 'out', 'account_id' => $account->id]) }}" class="btn-secondary">+ Kas Keluar</a>
            <a href="{{ route('cash-transactions.create', ['type' => 'in', 'account_id' => $account->id]) }}" class="btn-secondary">+ Kas Masuk</a>
        @endcan
    </x-slot:actions>

    <x-card class="mb-6">
        <form method="GET" action="{{ route('cash-bank.statement', $account) }}" class="flex flex-wrap items-end gap-2" x-data
              @change="if ($event.target.name === 'account') window.location = '{{ url('cash-bank/accounts') }}/' + $event.target.value + window.location.search">
            <div class="w-80"><x-form.select name="account" label="Rekening" :options="$options" :value="$account->id" :placeholder="false" /></div>
            <div class="w-40"><x-form.input name="from" label="Dari" :value="$from" data-date /></div>
            <div class="w-40"><x-form.input name="to" label="Sampai" :value="$to" data-date /></div>
            <button type="submit" class="btn-secondary">Tampilkan</button>
            <div class="ml-auto self-end"><x-report.export-buttons /></div>
        </form>
        <div class="mt-3"><x-report.period-presets /></div>
        @if ($account->bank_account_number)
            <p class="mt-3 text-sm text-gray-600">{{ $account->bank_name }} · No. rek. {{ $account->bank_account_number }}@if ($account->bank_account_holder) a.n. {{ $account->bank_account_holder }}@endif</p>
        @endif
    </x-card>

    <x-summary-tiles :tiles="[
        ['Saldo awal '.tanggal($from), rupiah($data['opening'])],
        ['Masuk / keluar', rupiah($data['debit'], false).' / '.rupiah($data['credit'], false)],
        ['Saldo akhir '.tanggal($to), rupiah($data['closing']), $data['closing'] < 0 ? 'red' : null],
    ]" />

    <x-card :title="$account->display_name" :padding="false">
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>Tanggal</th><th>Dokumen</th><th>Keterangan</th><th class="text-right">Masuk</th><th class="text-right">Keluar</th><th class="text-right">Saldo</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    <tr class="bg-gray-50">
                        <td colspan="5" class="font-medium">Saldo awal</td>
                        <td class="text-right font-medium whitespace-nowrap">{{ rupiah($data['opening'], false) }}</td>
                    </tr>
                    @forelse ($data['lines'] as $line)
                        <tr>
                            <td class="whitespace-nowrap">{{ tanggal($line['date']) }}</td>
                            <td class="text-sm">
                                @if ($url = $line['entry']->sourceUrl())
                                    <a href="{{ $url }}" class="text-indigo-600 hover:underline">{{ $line['source_number'] ?: $line['number'] }}</a>
                                @else
                                    {{ $line['source_number'] ?: $line['number'] }}
                                @endif
                            </td>
                            <td class="text-sm">{{ $line['description'] }}</td>
                            <td class="text-right whitespace-nowrap text-emerald-700">{{ $line['debit'] ? rupiah($line['debit'], false) : '' }}</td>
                            <td class="text-right whitespace-nowrap text-red-600">{{ $line['credit'] ? rupiah($line['credit'], false) : '' }}</td>
                            <td @class(['text-right whitespace-nowrap', 'text-red-600' => $line['balance'] < 0])>{{ rupiah($line['balance'], false) }}</td>
                        </tr>
                    @empty
                        <x-empty-row colspan="6" message="Tidak ada mutasi pada periode ini." />
                    @endforelse
                </tbody>
                <tfoot class="bg-gray-50 font-semibold">
                    <tr>
                        <td class="px-4 py-3" colspan="3">Total & saldo akhir</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($data['debit'], false) }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($data['credit'], false) }}</td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($data['closing'], false) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </x-card>
</x-layouts.app>
