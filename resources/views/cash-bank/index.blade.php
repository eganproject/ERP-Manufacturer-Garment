@use('App\Enums\CashTransactionType')
<x-layouts.app title="Kas & Bank">
    <x-slot:actions>
        <a href="{{ route('cash-transactions.index') }}" class="btn-secondary">Semua Bukti Kas</a>
        @can('cashbank.manage')
            @foreach (CashTransactionType::cases() as $type)
                <a href="{{ route('cash-transactions.create', ['type' => $type->value]) }}" class="{{ $type === CashTransactionType::Out ? 'btn-primary' : 'btn-secondary' }}">+ {{ $type->label() }}</a>
            @endforeach
        @endcan
    </x-slot:actions>

    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-indigo-200 bg-indigo-50 p-4 shadow-sm">
            <p class="text-sm text-indigo-700">Total kas & bank hari ini</p>
            <p @class(['mt-1 text-2xl font-semibold', 'text-indigo-900' => $total >= 0, 'text-red-600' => $total < 0])>{{ rupiah($total) }}</p>
            <p class="mt-1 text-xs text-indigo-700">{{ $accounts->count() }} rekening</p>
        </div>
        @foreach ($accounts as $row)
            @php $account = $row['account']; @endphp
            <a href="{{ route('cash-bank.statement', $account) }}" class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm hover:border-indigo-300">
                <p class="text-sm text-gray-500">{{ $account->code }} · {{ $account->name }}</p>
                <p @class(['mt-1 text-xl font-semibold', 'text-gray-900' => $row['balance'] >= 0, 'text-red-600' => $row['balance'] < 0])>{{ rupiah($row['balance']) }}</p>
                <p class="mt-1 text-xs text-gray-500">
                    @if ($account->bank_account_number){{ $account->bank_name }} {{ $account->bank_account_number }} · @endif
                    Bulan ini <span class="text-emerald-600">+{{ rupiah($row['inflow'], false) }}</span> / <span class="text-red-600">−{{ rupiah($row['outflow'], false) }}</span>
                </p>
            </a>
        @endforeach
    </div>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Bukti Kas Terakhir" :padding="false" class="lg:col-span-2">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>No. Bukti</th><th>Tanggal</th><th>Jenis</th><th>Rekening</th><th>Keterangan</th><th class="text-right">Jumlah</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($recent as $t)
                            <tr @class(['opacity-50' => $t->isCancelled()])>
                                <td><a href="{{ route('cash-transactions.show', $t) }}" class="font-medium text-indigo-600 hover:underline">{{ $t->number }}</a></td>
                                <td class="whitespace-nowrap">{{ tanggal($t->date) }}</td>
                                <td><x-badge :color="$t->type->color()">{{ $t->type->label() }}</x-badge></td>
                                <td class="text-sm">{{ $t->cashAccount->name }}@if ($t->toAccount) → {{ $t->toAccount->name }}@endif</td>
                                <td class="text-sm">{{ $t->description }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($t->amount) }}</td>
                            </tr>
                        @empty
                            <x-empty-row colspan="6" message="Belum ada bukti kas. Penerimaan pelanggan & pembayaran supplier tetap dicatat di menu masing-masing." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        <x-card title="Tentang Kas & Bank">
            <ul class="list-disc space-y-2 pl-4 text-sm text-gray-600">
                <li><b>Kas masuk / keluar</b> untuk uang di luar invoice & tagihan: biaya operasional, setoran modal, pinjaman, bunga, pembelian aset.</li>
                <li><b>Transfer</b> untuk memindahkan dana antar rekening sendiri (setor tunai, tarik kas kecil).</li>
                <li>Penerimaan pelanggan, pembayaran supplier, kasbon & upah borongan bisa memilih rekening yang dipakai.</li>
                <li>Klik rekening untuk melihat mutasi dan saldo berjalan (bisa diekspor ke Excel/PDF untuk rekonsiliasi bank).</li>
            </ul>
            @can('accounting.manage')
                <a href="{{ route('accounts.create', ['type' => 'asset', 'parent_id' => $cashParent, 'is_cash' => 1]) }}" class="btn-secondary mt-4 w-full justify-center">+ Tambah Rekening Kas/Bank</a>
            @endcan
        </x-card>
    </div>
</x-layouts.app>
