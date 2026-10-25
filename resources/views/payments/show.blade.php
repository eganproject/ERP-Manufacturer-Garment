@php
    $isReceipt = $payment->type === \App\Enums\PaymentType::Receipt;
    $party = $payment->party();
@endphp
<x-layouts.app :title="($isReceipt ? 'Penerimaan ' : 'Pembayaran ').$payment->number">
    <x-slot:actions>
        <a href="{{ route('payments.pdf', $payment) }}" target="_blank" class="btn-secondary">{{ $isReceipt ? 'Cetak Kwitansi' : 'Cetak Bukti Bayar' }}</a>
        @can($payment->type->manageAbility())
            @unless ($payment->isCancelled())
                <form method="POST" action="{{ route('payments.cancel', $payment) }}" class="inline" x-data x-ref="form">
                    @csrf
                    <input type="hidden" name="reason">
                    <button type="button" class="btn-secondary !text-red-600"
                            @click="Swal.fire({ title: 'Batalkan pembayaran?', text: 'Sisa tagihan pada dokumen terkait akan dikembalikan.', input: 'text', inputPlaceholder: 'Alasan pembatalan', icon: 'warning', showCancelButton: true, confirmButtonText: 'Ya, batalkan', cancelButtonText: 'Batal', confirmButtonColor: '#dc2626', reverseButtons: true, inputValidator: v => !v && 'Alasan wajib diisi' }).then(r => { if (r.isConfirmed) { $refs.form.querySelector('[name=reason]').value = r.value; $refs.form.submit(); } })">Batalkan</button>
                </form>
            @endunless
        @endcan
    </x-slot:actions>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Informasi">
            <dl class="space-y-3 text-sm">
                <div><dt class="text-gray-500">Jenis</dt><dd><x-badge :color="$payment->type->color()">{{ $payment->type->label() }}</x-badge></dd></div>
                <div><dt class="text-gray-500">Status</dt><dd><x-status-badge :status="$payment->status" /></dd></div>
                <div><dt class="text-gray-500">{{ $payment->type->partyLabel() }}</dt><dd class="font-medium">{{ $party?->name }}</dd></div>
                <div><dt class="text-gray-500">Tanggal</dt><dd>{{ tanggal($payment->payment_date) }}</dd></div>
                <div><dt class="text-gray-500">Metode</dt><dd>{{ $payment->method->label() }}@if ($payment->cashAccount) <span class="text-gray-500">· {{ $payment->cashAccount->name }}</span>@endif</dd></div>
                <div><dt class="text-gray-500">No. Referensi</dt><dd>{{ $payment->reference ?: '-' }}</dd></div>
                <div><dt class="text-gray-500">Jumlah</dt><dd class="text-lg font-semibold">{{ rupiah($payment->amount) }}</dd></div>
                <div><dt class="text-gray-500">Dicatat oleh</dt><dd>{{ $payment->creator?->name ?? '-' }}</dd></div>
                @if ($payment->notes)<div><dt class="text-gray-500">Catatan</dt><dd>{{ $payment->notes }}</dd></div>@endif
                @if ($payment->cancel_reason)<div><dt class="text-gray-500">Alasan Batal</dt><dd class="text-red-600">{{ $payment->cancel_reason }}</dd></div>@endif
            </dl>
        </x-card>

        <x-card :title="'Dialokasikan ke '.($isReceipt ? 'Invoice' : 'Tagihan')" :padding="false" class="lg:col-span-2">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>No. Dokumen</th><th>Jatuh Tempo</th><th class="text-right">Total Dokumen</th><th class="text-right">Dibayar</th><th>Status Dokumen</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($payment->allocations as $allocation)
                            @php $doc = $allocation->payable; @endphp
                            <tr>
                                <td><a href="{{ route($isReceipt ? 'invoices.show' : 'bills.show', $doc) }}" class="font-medium text-indigo-600 hover:underline">{{ $doc->number }}</a></td>
                                <td>{{ tanggal($doc->due_date) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($doc->total) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($allocation->amount) }}</td>
                                <td><x-payable-status :doc="$doc" /></td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr class="bg-gray-50 font-semibold"><td colspan="3" class="px-4 py-3 text-right">Total</td><td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($payment->amount) }}</td><td></td></tr></tfoot>
                </table>
            </div>
        </x-card>

        <x-journal-entries :sources="$payment" class="lg:col-span-3" />
        <x-activity-log :activities="$payment->activities" class="lg:col-span-3" />
    </div>
</x-layouts.app>
