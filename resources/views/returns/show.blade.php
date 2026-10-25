{{-- Detail retur penjualan / pembelian. $kind, $return, $openDocuments --}}
@php
    $party = $return->{$kind->partyRelation};
    $order = $return->{$kind->orderRelation};
    $status = $return->displayStatus();
    $showCost = $kind->isSales && auth()->user()->can('costing.view');
@endphp
<x-layouts.app :title="$kind->title.' '.$return->number">
    <x-slot:actions>
        <a href="{{ route($kind->route.'.pdf', $return) }}" target="_blank" class="btn-secondary">Cetak {{ $kind->noteLabel }}</a>
        @can($kind->manageCan)
            @if ($return->canCancel())
                <form method="POST" action="{{ route($kind->route.'.cancel', $return) }}" class="inline" x-data x-ref="form">
                    @csrf
                    <input type="hidden" name="reason">
                    <button type="button" class="btn-secondary !text-red-600"
                            @click="Swal.fire({ title: 'Batalkan retur?', text: 'Stok dikembalikan seperti sebelum retur dan nota yang sudah dipakai dilepas dari {{ strtolower($kind->documentLabel) }}.', input: 'text', inputPlaceholder: 'Alasan pembatalan', icon: 'warning', showCancelButton: true, confirmButtonText: 'Ya, batalkan', cancelButtonText: 'Batal', confirmButtonColor: '#dc2626', reverseButtons: true, inputValidator: v => !v && 'Alasan wajib diisi' }).then(r => { if (r.isConfirmed) { $refs.form.querySelector('[name=reason]').value = r.value; $refs.form.submit(); } })">Batalkan</button>
                </form>
            @endif
        @endcan
    </x-slot:actions>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Informasi">
            <dl class="space-y-3 text-sm">
                <div><dt class="text-gray-500">Status</dt><dd><x-badge :color="$status['color']">{{ $status['label'] }}</x-badge></dd></div>
                <div><dt class="text-gray-500">{{ $kind->partyLabel }}</dt><dd class="font-medium">{{ $party->name }}</dd><dd class="text-gray-500">{{ $party->phone }}</dd></div>
                <div>
                    <dt class="text-gray-500">{{ $kind->orderLabel }}</dt>
                    <dd>
                        @can($kind->orderCan)
                            <a href="{{ route($kind->orderRoute, $order) }}" class="font-medium text-indigo-600 hover:underline">{{ $order->number }}</a>
                        @else
                            {{ $order->number }}
                        @endcan
                    </dd>
                </div>
                <div><dt class="text-gray-500">Tanggal Retur</dt><dd>{{ tanggal($return->return_date) }}</dd></div>
                <div><dt class="text-gray-500">Alasan</dt><dd>{{ $return->reason }}</dd></div>
                <div><dt class="text-gray-500">Dicatat oleh</dt><dd>{{ $return->creator?->name ?? '-' }}</dd></div>
                @if ($showCost)
                    <div><dt class="text-gray-500">Nilai Stok Kembali (HPP)</dt><dd>{{ rupiah($return->items->sum('cost')) }}</dd></div>
                    <div><dt class="text-gray-500">Koreksi HPP Penjualan</dt><dd>{{ rupiah($return->billedCost()) }}</dd></div>
                @endif
                @if ($return->notes)<div><dt class="text-gray-500">Catatan</dt><dd>{{ $return->notes }}</dd></div>@endif
                @if ($return->cancel_reason)<div><dt class="text-gray-500">Alasan Batal</dt><dd class="text-red-600">{{ $return->cancel_reason }}</dd></div>@endif
            </dl>
        </x-card>

        <x-card title="Barang Diretur" :padding="false" class="lg:col-span-2">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>{{ $kind->itemLabel }}</th><th class="text-right">Qty Retur</th><th class="text-right">Sudah Ditagih</th><th class="text-right">Harga</th><th class="text-right">Nilai Nota (DPP)</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($return->items as $item)
                            <tr>
                                @if ($kind->isSales)
                                    <td>{{ $item->variant->display_name }}<div class="text-xs text-gray-500">{{ $item->variant->sku }}</div></td>
                                    <td class="text-right">{{ qty($item->quantity) }}</td>
                                @else
                                    <td>{{ $item->material->display_name }}</td>
                                    <td class="text-right">{{ qty($item->quantity) }} {{ $item->material->unit->code }}</td>
                                @endif
                                <td class="text-right">{{ qty($item->billed_quantity) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($item->price) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($item->amount) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr><td colspan="4" class="px-4 py-2 text-right">DPP</td><td class="px-4 py-2 text-right whitespace-nowrap">{{ rupiah($return->amount) }}</td></tr>
                        @if ((float) $return->tax_rate > 0)
                            <tr><td colspan="4" class="px-4 py-2 text-right">PPN {{ qty($return->tax_rate) }}%</td><td class="px-4 py-2 text-right whitespace-nowrap">{{ rupiah($return->tax_amount) }}</td></tr>
                        @endif
                        <tr class="font-semibold"><td colspan="4" class="px-4 py-2 text-right">Total {{ $kind->noteLabel }}</td><td class="px-4 py-2 text-right whitespace-nowrap">{{ rupiah($return->total) }}</td></tr>
                        <tr><td colspan="4" class="px-4 py-2 text-right text-emerald-700">Dipakai untuk {{ strtolower($kind->documentLabel) }}</td><td class="px-4 py-2 text-right whitespace-nowrap text-emerald-700">{{ rupiah($return->applied_amount) }}</td></tr>
                        @if ((float) $return->refunded_amount > 0)
                            <tr><td colspan="4" class="px-4 py-2 text-right text-emerald-700">Dikembalikan sebagai uang</td><td class="px-4 py-2 text-right whitespace-nowrap text-emerald-700">{{ rupiah($return->refunded_amount) }}</td></tr>
                        @endif
                        <tr class="font-semibold"><td colspan="4" class="px-4 py-3 text-right">Sisa Nota</td><td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($return->balance()) }}</td></tr>
                    </tfoot>
                </table>
            </div>
            @if ((float) $return->total <= 0)
                <p class="border-t border-gray-100 px-5 py-3 text-xs text-gray-500">Semua barang retur belum ditagih, jadi tidak ada {{ strtolower($kind->noteLabel) }}. Jumlah yang bisa ditagih pada {{ $kind->isSales ? 'SO' : 'PO' }} sudah dikurangi.</p>
            @endif
        </x-card>

        <x-card :title="$kind->noteLabel.' Dipakai untuk'" :padding="false" class="lg:col-span-3">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>No. {{ $kind->documentLabel }}</th><th>Tanggal Pakai</th><th class="text-right">Total Dokumen</th><th class="text-right">Dikurangi</th><th>Status Dokumen</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($return->applications as $application)
                            @php $doc = $application->payable; @endphp
                            <tr>
                                <td><a href="{{ route($kind->documentRoute, $doc) }}" class="font-medium text-indigo-600 hover:underline">{{ $doc->number }}</a></td>
                                <td>{{ tanggal($application->applied_date) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($doc->total) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($application->amount) }}</td>
                                <td><x-payable-status :doc="$doc" /></td>
                            </tr>
                        @empty
                            <x-empty-row colspan="5" :message="'Nota belum dipakai untuk '.strtolower($kind->documentLabel).'.'" />
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ((float) $return->refunded_amount > 0)
                <p class="border-t border-gray-100 px-5 py-3 text-sm text-gray-600">
                    Pengembalian dana {{ rupiah($return->refunded_amount) }} · {{ tanggal($return->refund_date) }} · {{ \App\Enums\PaymentMethod::tryFrom($return->refund_method)?->label() }}
                    @if ($return->refund_reference) · Ref. {{ $return->refund_reference }} @endif
                </p>
            @endif
        </x-card>

        @if ($return->hasBalance())
            @can($kind->financeCan)
                <x-card :title="'Pakai Sisa Nota ('.rupiah($return->balance()).')'">
                    @if ($openDocuments->isEmpty())
                        <p class="text-sm text-gray-500">Tidak ada {{ strtolower($kind->documentLabel) }} terbuka untuk {{ $party->name }}.</p>
                    @else
                        <form method="POST" action="{{ route($kind->route.'.apply', $return) }}" class="space-y-3">
                            @csrf
                            <x-form.select name="document_id" :label="$kind->documentLabel" required data-select
                                :options="$openDocuments->mapWithKeys(fn ($d) => [$d->id => $d->number.' · sisa '.rupiah($d->outstanding())])" />
                            <x-form.input name="amount" label="Jumlah" required data-money="2" :value="min($return->balance(), $openDocuments->first()->outstanding())" />
                            <button type="submit" class="btn-primary w-full">Kurangi {{ $kind->documentLabel }}</button>
                        </form>
                    @endif
                </x-card>
                <x-card :title="$kind->refundLabel" class="lg:col-span-2">
                    <form method="POST" action="{{ route($kind->route.'.refund', $return) }}" class="grid grid-cols-1 gap-4 md:grid-cols-4"
                          data-confirm="Sisa nota dicatat sudah dikembalikan sebagai uang." data-confirm-icon="question" data-confirm-button="Ya, catat">
                        @csrf
                        <x-form.input name="refund_date" label="Tanggal" :value="now()" required data-date />
                        <x-form.select name="refund_method" label="Metode" :options="\App\Enums\PaymentMethod::options()" value="transfer" :placeholder="false" required />
                        <x-form.cash-account name="refund_account_id" />
                        <x-form.input name="refund_reference" label="No. Referensi" />
                        <x-form.input name="amount" label="Jumlah" required data-money="2" :value="$return->balance()" />
                        <div class="md:col-span-3 flex items-end justify-end"><button type="submit" class="btn-success">Catat Pengembalian Dana</button></div>
                    </form>
                </x-card>
            @endcan
        @endif

        <x-journal-entries :sources="$return" class="lg:col-span-3" />
        <x-activity-log :activities="$return->activities" class="lg:col-span-3" />
    </div>
</x-layouts.app>
