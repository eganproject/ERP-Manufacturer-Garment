{{-- Daftar retur penjualan / pembelian. $kind: App\Support\ReturnKind --}}
<x-layouts.app :title="$kind->title">
    @can($kind->manageCan)
        <x-slot:actions>
            <a href="{{ route($kind->route.'.create') }}" class="btn-primary">+ Catat Retur</a>
        </x-slot:actions>
    @endcan

    <x-summary-tiles :tiles="[
        ['Jumlah retur', $summary->count.' dokumen'],
        ['Nilai '.strtolower($kind->noteLabel), rupiah($summary->total)],
        ['Sisa nota belum dipakai', rupiah($summary->balance), $summary->balance > 0 ? 'amber' : null],
    ]" />

    <x-card :padding="false">
        <div class="border-b border-gray-200 p-4">
            <x-search-filter :placeholder="'Cari no. retur / '.($kind->isSales ? 'SO' : 'PO').' / '.strtolower($kind->partyLabel).'...'">
                <div class="w-56"><x-form.select name="party_id" :options="$parties" :value="request('party_id')" :placeholder="'Semua '.strtolower($kind->partyLabel)" data-select /></div>
                <div class="w-40"><x-form.select name="status" :options="\App\Enums\ReturnStatus::options()" :value="request('status')" placeholder="Semua status" /></div>
                <div class="w-36"><x-form.input name="from" :value="request('from')" placeholder="Dari tanggal" data-date /></div>
                <div class="w-36"><x-form.input name="to" :value="request('to')" placeholder="Sampai tanggal" data-date /></div>
            </x-search-filter>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>No. Retur</th><th>{{ $kind->partyLabel }}</th><th>{{ $kind->isSales ? 'SO' : 'PO' }}</th><th>Tanggal</th><th>Alasan</th><th class="text-right">Qty</th><th class="text-right">{{ $kind->noteLabel }}</th><th class="text-right">Sisa Nota</th><th>Status</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($returns as $return)
                        @php $status = $return->displayStatus(); @endphp
                        <tr>
                            <td><a href="{{ route($kind->route.'.show', $return) }}" class="font-medium text-indigo-600 hover:underline">{{ $return->number }}</a></td>
                            <td>{{ $return->{$kind->partyRelation}->name }}</td>
                            <td>{{ $return->{$kind->orderRelation}->number }}</td>
                            <td>{{ tanggal($return->return_date) }}</td>
                            <td class="max-w-xs truncate">{{ $return->reason }}</td>
                            <td class="text-right">{{ qty($return->items->sum('quantity')) }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($return->total) }}</td>
                            <td @class(['text-right whitespace-nowrap', 'text-amber-600 font-medium' => $return->hasBalance()])>{{ rupiah($return->balance()) }}</td>
                            <td><x-badge :color="$status['color']">{{ $status['label'] }}</x-badge></td>
                        </tr>
                    @empty
                        <x-empty-row colspan="9" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4">{{ $returns->links() }}</div>
    </x-card>
</x-layouts.app>
