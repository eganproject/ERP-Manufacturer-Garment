@use('App\Enums\CashTransactionType')
@php $isIn = $transaction->type === CashTransactionType::In; @endphp
<x-layouts.app :title="$transaction->type->label().' '.$transaction->number">
    <x-slot:actions>
        <a href="{{ route('cash-transactions.pdf', $transaction) }}" target="_blank" class="btn-secondary">Cetak Bukti</a>
        @can('cashbank.manage')
            @unless ($transaction->isCancelled())
                <x-reason-form :action="route('cash-transactions.cancel', $transaction)" title="Batalkan bukti kas?" text="Jurnalnya akan dibalik." placeholder="Alasan pembatalan" button="Ya, batalkan" class="btn-outline-danger">Batalkan</x-reason-form>
            @endunless
        @endcan
    </x-slot:actions>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Informasi">
            <dl class="space-y-3 text-sm">
                <div><dt class="text-gray-500">Jenis</dt><dd><x-badge :color="$transaction->type->color()">{{ $transaction->type->label() }}</x-badge></dd></div>
                <div><dt class="text-gray-500">Status</dt><dd><x-status-badge :status="$transaction->status" />@if ($transaction->cancel_reason)<span class="ml-1 text-gray-500">{{ $transaction->cancel_reason }}</span>@endif</dd></div>
                <div><dt class="text-gray-500">Tanggal</dt><dd>{{ tanggal($transaction->date) }}</dd></div>
                <div>
                    <dt class="text-gray-500">{{ $transaction->type === CashTransactionType::Transfer ? 'Dari → Ke' : 'Rekening' }}</dt>
                    <dd>
                        <a href="{{ route('cash-bank.statement', $transaction->cash_account_id) }}" class="text-indigo-600 hover:underline">{{ $transaction->cashAccount->display_name }}</a>
                        @if ($transaction->toAccount)
                            → <a href="{{ route('cash-bank.statement', $transaction->to_account_id) }}" class="text-indigo-600 hover:underline">{{ $transaction->toAccount->display_name }}</a>
                        @endif
                    </dd>
                </div>
                @if ($transaction->party_name)
                    <div><dt class="text-gray-500">{{ $isIn ? 'Diterima dari' : 'Dibayar ke' }}</dt><dd class="font-medium">{{ $transaction->party_name }}</dd></div>
                @endif
                <div><dt class="text-gray-500">No. Referensi</dt><dd>{{ $transaction->reference ?: '-' }}</dd></div>
                <div><dt class="text-gray-500">Keterangan</dt><dd>{{ $transaction->description }}</dd></div>
                <div><dt class="text-gray-500">Jumlah</dt><dd class="text-lg font-semibold">{{ rupiah($transaction->amount) }}</dd></div>
                <div><dt class="text-gray-500">Dicatat oleh</dt><dd>{{ $transaction->creator?->name ?? '-' }}</dd></div>
            </dl>
        </x-card>

        <div class="space-y-6 lg:col-span-2">
            @if ($transaction->lines->isNotEmpty())
                <x-card :title="$isIn ? 'Sumber Penerimaan' : 'Penggunaan Dana'" :padding="false">
                    <table class="table-base">
                        <thead><tr><th>Akun</th><th>Keterangan</th><th class="text-right">Jumlah</th></tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($transaction->lines as $line)
                                <tr>
                                    <td>{{ $line->account->display_name }}</td>
                                    <td class="text-sm">{{ $line->description ?: '-' }}</td>
                                    <td class="text-right whitespace-nowrap">{{ rupiah($line->amount) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-gray-50 font-semibold"><tr><td colspan="2" class="px-4 py-3">Total</td><td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($transaction->amount) }}</td></tr></tfoot>
                    </table>
                </x-card>
            @endif
            <x-journal-entries :sources="$transaction" />
            <x-activity-log :activities="$transaction->activities" />
        </div>
    </div>
</x-layouts.app>
