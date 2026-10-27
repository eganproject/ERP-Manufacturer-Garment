@php [$statusLabel, $statusColor] = $quotation->displayStatus(); @endphp
<x-layouts.app :title="$quotation->number" :subtitle="'Penawaran Harga · '.$quotation->customer->name">
    <x-slot:actions>
        <x-more-actions>
            <a href="{{ route('quotations.pdf', $quotation) }}" target="_blank" class="menu-item">Cetak Penawaran</a>
            @can('sales.manage')
                @if ($quotation->isEditable())
                    <a href="{{ route('quotations.edit', $quotation) }}" class="menu-item">Ubah Penawaran</a>
                @endif
                @if ($quotation->canRevise())
                    <x-action-form :action="route('quotations.revise', $quotation)" confirm="Penawaran disalin menjadi revisi baru (draft) dengan masa berlaku baru." icon="question" button="Ya, buat revisi" class="menu-item">Buat Revisi</x-action-form>
                @endif
                @if ($quotation->isOpen() || ($quotation->isEditable() && $quotation->revisions->isEmpty()))
                    <div class="menu-divider"></div>
                @endif
                @if ($quotation->isOpen())
                    <x-reason-form :action="route('quotations.cancel', $quotation)" title="Batalkan penawaran?" text="Penawaran tidak dilanjutkan (bukan ditolak pelanggan)." placeholder="Alasan pembatalan" button="Ya, batalkan" class="menu-item-danger">Batalkan Penawaran</x-reason-form>
                @endif
                @if ($quotation->isEditable() && $quotation->revisions->isEmpty())
                    <x-delete-button :action="route('quotations.destroy', $quotation)" label="Hapus Penawaran" class="menu-item-danger" />
                @endif
            @endcan
        </x-more-actions>
        @can('sales.manage')
            @if ($quotation->status === \App\Enums\QuotationStatus::Sent && ! $quotation->isExpired())
                <x-reason-form :action="route('quotations.reject', $quotation)" title="Pelanggan menolak penawaran?" placeholder="Alasan, mis. harga terlalu tinggi" button="Ya, ditolak">Ditolak Pelanggan</x-reason-form>
            @endif
            @if ($quotation->canSend())
                <x-action-form :action="route('quotations.send', $quotation)" confirm="Tandai penawaran sudah dikirim ke pelanggan? Setelah ini isi penawaran terkunci." icon="question" button="Ya, tandai terkirim" class="btn-primary">Tandai Terkirim</x-action-form>
            @endif
        @endcan
    </x-slot:actions>

    @if ($quotation->isExpired())
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            Penawaran ini sudah lewat masa berlaku ({{ tanggal($quotation->valid_until) }}). Buat revisi dengan harga & masa berlaku baru sebelum dijadikan SO.
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6">
            <x-card title="Informasi">
                <dl class="space-y-3 text-sm">
                    <div><dt class="text-gray-500">Status</dt><dd><x-badge :color="$statusColor">{{ $statusLabel }}</x-badge>
                        @if ($quotation->reject_reason)<div class="mt-1 text-gray-600">{{ $quotation->reject_reason }}</div>@endif</dd></div>
                    <div><dt class="text-gray-500">Pelanggan</dt><dd class="font-medium">{{ $quotation->customer->name }}</dd><dd class="text-gray-500">{{ $quotation->customer->phone }}</dd></div>
                    @if ($quotation->subject)<div><dt class="text-gray-500">Perihal</dt><dd>{{ $quotation->subject }}</dd></div>@endif
                    <div><dt class="text-gray-500">Tanggal / Berlaku s.d.</dt><dd>{{ tanggal($quotation->quotation_date) }} – {{ tanggal($quotation->valid_until) }}
                        @if ($quotation->isOpen() && ! $quotation->isExpired())<span class="text-gray-500">(sisa {{ $quotation->daysLeft() }} hari)</span>@endif</dd></div>
                    @if ($quotation->lead_time_days)<div><dt class="text-gray-500">Estimasi Selesai</dt><dd>{{ $quotation->lead_time_days }} hari setelah order</dd></div>@endif
                    @if ($quotation->sent_at)<div><dt class="text-gray-500">Dikirim</dt><dd>{{ $quotation->sent_at->translatedFormat('d M Y H:i') }}</dd></div>@endif
                    @if ($quotation->salesOrder)
                        <div><dt class="text-gray-500">Sales Order</dt><dd><a href="{{ route('sales-orders.show', $quotation->salesOrder) }}" class="font-medium text-indigo-600 hover:underline">{{ $quotation->salesOrder->number }}</a> <x-status-badge :status="$quotation->salesOrder->status" /></dd></div>
                    @endif
                    @if ($quotation->revisedFrom)
                        <div><dt class="text-gray-500">Revisi dari</dt><dd><a href="{{ route('quotations.show', $quotation->revisedFrom) }}" class="text-indigo-600 hover:underline">{{ $quotation->revisedFrom->number }}</a></dd></div>
                    @endif
                    @foreach ($quotation->revisions as $revision)
                        <div><dt class="text-gray-500">Direvisi menjadi</dt><dd><a href="{{ route('quotations.show', $revision) }}" class="text-indigo-600 hover:underline">{{ $revision->number }}</a></dd></div>
                    @endforeach
                    <div><dt class="text-gray-500">Dibuat oleh</dt><dd>{{ $quotation->creator?->name ?? '-' }}</dd></div>
                    @if ($quotation->terms)<div><dt class="text-gray-500">Syarat & Ketentuan</dt><dd class="whitespace-pre-line">{{ $quotation->terms }}</dd></div>@endif
                    @if ($quotation->notes)<div><dt class="text-gray-500">Catatan Internal</dt><dd>{{ $quotation->notes }}</dd></div>@endif
                </dl>
            </x-card>

            @can('sales.manage')
                @if ($quotation->canConvert())
                    <x-card title="Pelanggan Setuju?">
                        <form method="POST" action="{{ route('quotations.convert', $quotation) }}" class="space-y-3"
                              data-confirm="Sales Order draft akan dibuat dari penawaran ini." data-confirm-icon="question" data-confirm-button="Ya, buat SO">
                            @csrf
                            <x-form.input name="order_date" label="Tanggal Order" :value="today()" data-date />
                            <x-form.input name="delivery_date" label="Target Kirim" :value="$quotation->lead_time_days ? today()->addDays($quotation->lead_time_days) : null" data-date />
                            <button type="submit" class="btn-success w-full justify-center">Jadikan Sales Order</button>
                            <p class="text-xs text-gray-500">Item, harga & diskon disalin ke SO draft. PPN ditetapkan saat invoice.</p>
                        </form>
                    </x-card>
                @endif
            @endcan
        </div>

        <x-card title="Item" :padding="false" class="lg:col-span-2">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>Produk</th><th class="text-right">Qty</th><th class="text-right">Stok</th><th class="text-right">Harga</th><th class="text-right">Subtotal</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($quotation->items as $item)
                            <tr>
                                <td>{{ $item->variant->display_name }}<div class="text-xs text-gray-500">{{ $item->variant->sku }}@if ($item->description) · {{ $item->description }}@endif</div></td>
                                <td class="text-right">{{ qty($item->quantity) }}</td>
                                <td class="text-right text-gray-500">{{ qty($item->variant->stock) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($item->price) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($item->subtotal) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-gray-50">
                        <tr><td colspan="4" class="px-4 py-2 text-right">Subtotal</td><td class="px-4 py-2 text-right whitespace-nowrap">{{ rupiah($quotation->subtotal) }}</td></tr>
                        @if ((float) $quotation->discount > 0)
                            <tr><td colspan="4" class="px-4 py-2 text-right">Diskon</td><td class="px-4 py-2 text-right whitespace-nowrap">- {{ rupiah($quotation->discount) }}</td></tr>
                        @endif
                        @if ((float) $quotation->tax_rate > 0)
                            <tr><td colspan="4" class="px-4 py-2 text-right">PPN {{ qty($quotation->tax_rate) }}%</td><td class="px-4 py-2 text-right whitespace-nowrap">{{ rupiah($quotation->tax_amount) }}</td></tr>
                        @endif
                        <tr class="font-semibold"><td colspan="4" class="px-4 py-3 text-right">Total Penawaran</td><td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($quotation->total) }}</td></tr>
                    </tfoot>
                </table>
            </div>
        </x-card>

        <x-activity-log :activities="$quotation->activities" class="lg:col-span-3" />
    </div>
</x-layouts.app>
