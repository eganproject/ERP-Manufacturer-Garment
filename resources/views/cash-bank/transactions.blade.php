@use('App\Enums\CashTransactionType')
<x-layouts.app title="Bukti Kas & Bank">
    <x-slot:actions>
        <a href="{{ route('cash-bank.index') }}" class="btn-secondary">Rekening</a>
        @can('cashbank.manage')
            @foreach (CashTransactionType::cases() as $type)
                <a href="{{ route('cash-transactions.create', ['type' => $type->value]) }}" class="{{ $type === CashTransactionType::Out ? 'btn-primary' : 'btn-secondary' }}">+ {{ $type->label() }}</a>
            @endforeach
        @endcan
    </x-slot:actions>

    <x-summary-tiles :tiles="[
        ['Kas masuk '.tanggal($from).' – '.tanggal($to), rupiah($totals['in'] ?? 0)],
        ['Kas keluar', rupiah($totals['out'] ?? 0), 'red'],
        ['Transfer antar rekening', rupiah($totals['transfer'] ?? 0)],
    ]" />

    <x-card :padding="false">
        <div class="border-b border-gray-100 p-4">
            <x-search-filter placeholder="Cari no. bukti / keterangan / pihak...">
                <div class="w-40"><x-form.input name="from" :value="$from" data-date /></div>
                <div class="w-40"><x-form.input name="to" :value="$to" data-date /></div>
                <div class="w-48"><x-form.select name="type" :options="CashTransactionType::options()" :value="request('type')" placeholder="Semua jenis" /></div>
                <div class="w-56"><x-form.select name="account_id" :options="$cashAccounts" :value="request('account_id')" placeholder="Semua rekening" /></div>
                <div class="w-40"><x-form.select name="status" :options="['posted' => 'Tercatat', 'cancelled' => 'Dibatalkan']" :value="request('status')" placeholder="Semua status" /></div>
            </x-search-filter>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>No. Bukti</th><th>Tanggal</th><th>Jenis</th><th>Rekening</th><th>Pihak</th><th>Keterangan</th><th class="text-right">Jumlah</th><th>Status</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($transactions as $t)
                        <tr>
                            <td><a href="{{ route('cash-transactions.show', $t) }}" class="font-medium text-indigo-600 hover:underline">{{ $t->number }}</a></td>
                            <td class="whitespace-nowrap">{{ tanggal($t->date) }}</td>
                            <td><x-badge :color="$t->type->color()">{{ $t->type->label() }}</x-badge></td>
                            <td class="text-sm">{{ $t->cashAccount->name }}@if ($t->toAccount) → {{ $t->toAccount->name }}@endif</td>
                            <td class="text-sm">{{ $t->party_name ?: '-' }}</td>
                            <td class="text-sm">{{ $t->description }}@if ($t->reference)<div class="text-xs text-gray-500">Ref. {{ $t->reference }}</div>@endif</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($t->amount) }}</td>
                            <td><x-status-badge :status="$t->status" /></td>
                        </tr>
                    @empty
                        <x-empty-row colspan="8" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-4 py-3 empty:hidden">{{ $transactions->links() }}</div>
    </x-card>
</x-layouts.app>
