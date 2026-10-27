<x-layouts.app :title="'Transfer '.$transfer->number">
    <x-slot:actions>
        <a href="{{ route('stock-transfers.pdf', $transfer) }}" target="_blank" class="btn-secondary">Cetak Surat Transfer</a>
        @can('stock.transfer')
            @unless ($transfer->isCancelled())
                <x-reason-form :action="route('stock-transfers.cancel', $transfer)" title="Batalkan transfer?" text="Barang dikembalikan ke gudang asal (stok di gudang tujuan harus cukup)." button="Ya, batalkan" class="btn-outline-danger">Batalkan</x-reason-form>
            @endunless
        @endcan
    </x-slot:actions>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Informasi">
            <dl class="space-y-3 text-sm">
                <div><dt class="text-gray-500">Status</dt><dd><x-status-badge :status="$transfer->status" />@if ($transfer->cancel_reason)<div class="mt-1 text-gray-600">{{ $transfer->cancel_reason }}</div>@endif</dd></div>
                <div><dt class="text-gray-500">Tanggal</dt><dd>{{ tanggal($transfer->date) }}</dd></div>
                <div><dt class="text-gray-500">Dari → Ke</dt><dd class="font-medium">{{ $transfer->fromWarehouse->name }} → {{ $transfer->toWarehouse->name }}</dd></div>
                <div><dt class="text-gray-500">Dibuat oleh</dt><dd>{{ $transfer->creator?->name ?? '-' }}</dd></div>
                @if ($transfer->notes)<div><dt class="text-gray-500">Catatan</dt><dd>{{ $transfer->notes }}</dd></div>@endif
            </dl>
        </x-card>

        <x-card title="Barang" :padding="false" class="lg:col-span-2">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>Barang</th><th class="text-right">Jumlah</th>@can('costing.view')<th class="text-right">Nilai</th>@endcan</tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($transfer->items as $line)
                            <tr>
                                <td>{{ \App\Services\WarehouseStockService::itemName($line->stockable) }}</td>
                                <td class="text-right">{{ qty($line->quantity) }}</td>
                                @can('costing.view')<td class="text-right whitespace-nowrap">{{ rupiah((float) $line->quantity * (float) $line->unit_cost) }}</td>@endcan
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <x-activity-log :activities="$transfer->activities" class="lg:col-span-3" />
    </div>
</x-layouts.app>
