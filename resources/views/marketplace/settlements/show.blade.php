<x-layouts.app :title="'Pencairan '.$settlement->number">
    <x-slot:actions>
        <a href="{{ route('marketplace-settlements.pdf', $settlement) }}" target="_blank" class="btn-secondary">Cetak</a>
        @can('marketplace.settle')
            @if ($settlement->isDraft())
                <x-action-form :action="route('marketplace-settlements.rematch', $settlement)">Cocokkan Ulang Pesanan</x-action-form>
                <x-action-form :action="route('marketplace-settlements.post', $settlement)" class="btn-primary" confirm="Piutang marketplace pesanan ditutup, biaya platform & dana masuk dijurnal." button="Ya, posting">Posting</x-action-form>
                <x-delete-button :action="route('marketplace-settlements.destroy', $settlement)" class="btn-outline-danger" label="Hapus Draft" />
            @elseif ($settlement->isPosted())
                <x-reason-form :action="route('marketplace-settlements.cancel', $settlement)" title="Batalkan pencairan?" text="Jurnal dibalik dan piutang pesanan dibuka kembali." button="Ya, batalkan" class="btn-outline-danger">Batalkan</x-reason-form>
            @endif
        @endcan
    </x-slot:actions>

    @php
        $unmatched = $settlement->lines->filter(fn ($l) => $l->order_number && ! $l->order);
        $notShipped = $settlement->lines->filter(fn ($l) => $l->order && ! $l->order->isShipped());
        // Pesanan yang sudah pernah cair di pencairan lain (file sama diimpor dua kali?)
        $duplicates = $settlement->isDraft() ? $settlement->lines->filter(fn ($l) => $l->order && $l->order->settlementLines()
            ->where('marketplace_settlement_id', '!=', $settlement->id)->whereHas('settlement', fn ($q) => $q->where('status', 'posted'))->exists()) : collect();
    @endphp

    <x-summary-tiles :tiles="[
        ['Nilai pesanan', rupiah($settlement->order_amount)],
        ['Voucher + biaya + ongkir', rupiah((float) $settlement->voucher + (float) $settlement->fees + (float) $settlement->shipping_fee)],
        ['Dana bersih masuk '.$settlement->store->balanceAccountName(), rupiah($settlement->net_amount)],
    ]" />

    @if ($duplicates->isNotEmpty())
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            {{ $duplicates->count() }} pesanan sudah pernah cair di pencairan lain ({{ $duplicates->pluck('order_number')->take(5)->implode(', ') }}). Pastikan file ini bukan file yang sama yang diimpor dua kali; bila hanya penyesuaian susulan dari platform, boleh diposting.
        </div>
    @endif

    @if ($settlement->isDraft() && ($unmatched->isNotEmpty() || $notShipped->isNotEmpty()))
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            @if ($notShipped->isNotEmpty())<p>{{ $notShipped->count() }} pesanan belum dikirim di ERP ({{ $notShipped->pluck('order_number')->take(5)->implode(', ') }}). Impor file pesanan terbaru agar statusnya ikut diperbarui, lalu posting.</p>@endif
            @if ($unmatched->isNotEmpty())<p>{{ $unmatched->count() }} baris tidak ditemukan pesanannya ({{ $unmatched->pluck('order_number')->take(5)->implode(', ') }}). Impor pesanannya lalu klik "Cocokkan Ulang", atau posting apa adanya (nilainya dicatat sebagai biaya / penyesuaian).</p>@endif
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
        <x-card title="Informasi">
            <dl class="space-y-3 text-sm">
                <div><dt class="text-gray-500">Status</dt><dd><x-status-badge :status="$settlement->status" />@if ($settlement->cancel_reason)<div class="mt-1 text-gray-600">{{ $settlement->cancel_reason }}</div>@endif</dd></div>
                <div><dt class="text-gray-500">Toko</dt><dd class="font-medium">{{ $settlement->store->display_name }}</dd></div>
                <div><dt class="text-gray-500">Tanggal pencairan</dt><dd>{{ tanggal($settlement->date) }}</dd></div>
                @if ($settlement->source_file)<div><dt class="text-gray-500">File</dt><dd class="break-all">{{ $settlement->source_file }}</dd></div>@endif
                <div><dt class="text-gray-500">Rincian potongan</dt><dd>Voucher {{ rupiah($settlement->voucher) }}<br>Biaya platform {{ rupiah($settlement->fees) }}<br>Ongkir penjual {{ rupiah($settlement->shipping_fee) }}<br>Penyesuaian {{ rupiah($settlement->adjustment) }}</dd></div>
                @if ($settlement->isPosted())
                    <div><dt class="text-gray-500">Piutang ditutup</dt><dd>{{ rupiah($settlement->receivable) }}</dd></div>
                    <div><dt class="text-gray-500">Selisih pencairan</dt><dd>{{ rupiah($settlement->difference()) }}</dd></div>
                    <div><dt class="text-gray-500">Diposting</dt><dd>{{ $settlement->poster?->name }} · {{ $settlement->posted_at?->translatedFormat('d M Y H:i') }}</dd></div>
                @endif
                @if ($settlement->notes)<div><dt class="text-gray-500">Catatan</dt><dd>{{ $settlement->notes }}</dd></div>@endif
            </dl>
        </x-card>

        <x-card title="Rincian" :padding="false" class="lg:col-span-3">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>Pesanan</th><th class="text-right">Nilai Pesanan</th><th class="text-right">Voucher</th><th class="text-right">Komisi</th><th class="text-right">Layanan</th><th class="text-right">Transaksi</th><th class="text-right">Ongkir</th><th class="text-right">Lain</th><th class="text-right">Penyesuaian</th><th class="text-right">Dana</th>@if ($settlement->isPosted())<th class="text-right">Piutang</th>@endif</tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($settlement->lines as $line)
                            <tr>
                                <td class="whitespace-nowrap">
                                    @if ($line->order)<a href="{{ route('marketplace-orders.show', $line->order) }}" class="text-indigo-600 hover:underline">{{ $line->order_number }}</a>
                                    @else<span @class(['text-amber-600' => $line->order_number])>{{ $line->order_number ?? 'Bukan pesanan' }}</span>@endif
                                    @if ($line->description)<div class="text-xs text-gray-500">{{ $line->description }}</div>@endif
                                </td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($line->order_amount, false) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($line->voucher, false) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($line->commission, false) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($line->service_fee, false) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($line->transaction_fee, false) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($line->shipping_fee, false) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($line->other_fee, false) }}</td>
                                <td @class(['text-right whitespace-nowrap', 'text-amber-600' => abs((float) $line->adjustment) > 0.005])>{{ rupiah($line->adjustment, false) }}</td>
                                <td class="text-right font-medium whitespace-nowrap">{{ rupiah($line->net_amount, false) }}</td>
                                @if ($settlement->isPosted())<td class="text-right whitespace-nowrap">{{ rupiah($line->receivable, false) }}</td>@endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <x-journal-entries :sources="$settlement" class="lg:col-span-4" />
        <x-activity-log :activities="$settlement->activities" class="lg:col-span-4" />
    </div>
</x-layouts.app>
