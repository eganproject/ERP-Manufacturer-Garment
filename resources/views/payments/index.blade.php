@php
    $isReceipt = $type === \App\Enums\PaymentType::Receipt;
    $indexRoute = $isReceipt ? 'receipts' : 'disbursements';
@endphp
<x-layouts.app :title="$isReceipt ? 'Penerimaan dari Pelanggan' : 'Pembayaran ke Supplier'">
    @can($type->manageAbility())
        <x-slot:actions><a href="{{ route($indexRoute.'.create') }}" class="btn-primary">+ {{ $isReceipt ? 'Catat Penerimaan' : 'Catat Pembayaran' }}</a></x-slot:actions>
    @endcan

    <x-card :padding="false">
        <div class="border-b border-gray-200 p-4">
            <x-search-filter :placeholder="'Cari no. bukti / referensi / '.strtolower($type->partyLabel()).'...'">
                <div class="w-44"><x-form.select name="status" :options="['posted' => 'Tercatat', 'cancelled' => 'Dibatalkan']" :value="request('status')" placeholder="Semua status" /></div>
            </x-search-filter>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>No. Bukti</th><th>{{ $type->partyLabel() }}</th><th>Tanggal</th><th>Metode</th><th>Referensi</th><th class="text-right">Jumlah</th><th>Status</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($payments as $payment)
                        <tr>
                            <td><a href="{{ route('payments.show', $payment) }}" class="font-medium text-indigo-600 hover:underline">{{ $payment->number }}</a></td>
                            <td>{{ $payment->party()?->name }}</td>
                            <td>{{ tanggal($payment->payment_date) }}</td>
                            <td>{{ $payment->method->label() }}</td>
                            <td>{{ $payment->reference ?: '-' }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($payment->amount) }}</td>
                            <td><x-status-badge :status="$payment->status" /></td>
                        </tr>
                    @empty
                        <x-empty-row colspan="7" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-4 py-3 empty:hidden">{{ $payments->links() }}</div>
    </x-card>
</x-layouts.app>
