@use('App\Enums\DefectHandlingType')
<x-layouts.app :title="$handling->type->label().' '.$handling->number">
    <x-slot:actions>
        <a href="{{ route('defects.index') }}" class="btn-secondary">Barang Cacat</a>
        <a href="{{ route('defects.pdf', $handling) }}" target="_blank" class="btn-secondary">Cetak</a>
        @can('defects.manage')
            @unless ($handling->isCancelled())
                <x-reason-form :action="route('defects.cancel', $handling)" title="Batalkan dokumen ini?" text="Stok dikembalikan ke lokasi semula dan jurnal dibalik." class="!text-red-600">Batalkan</x-reason-form>
            @endunless
        @endcan
    </x-slot:actions>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Informasi">
            <dl class="space-y-3 text-sm">
                <div><dt class="text-gray-500">Jenis</dt><dd><x-badge :color="$handling->type->color()">{{ $handling->type->label() }}</x-badge></dd></div>
                <div><dt class="text-gray-500">Status</dt><dd><x-status-badge :status="$handling->status" />@if ($handling->cancel_reason)<span class="ml-1 text-gray-500">{{ $handling->cancel_reason }}</span>@endif</dd></div>
                <div><dt class="text-gray-500">Tanggal</dt><dd>{{ tanggal($handling->date) }}</dd></div>
                <x-warehouse-tag :warehouse="$handling->warehouse" block />
                <div><dt class="text-gray-500">Alur Stok</dt><dd>{{ $handling->type->fromDefect() ? 'Stok cacat' : 'Stok jual' }} → {{ match ($handling->type) { DefectHandlingType::Scrap => 'dimusnahkan', DefectHandlingType::Quarantine => 'stok cacat', default => 'stok jual' } }}</dd></div>
                @if ($handling->notes)<div><dt class="text-gray-500">Catatan</dt><dd>{{ $handling->notes }}</dd></div>@endif
                @can('costing.view')
                    <div><dt class="text-gray-500">Nilai Barang (HPP)</dt><dd class="font-semibold">{{ rupiah($handling->total_cost) }}</dd></div>
                    @if ((float) $handling->extra_cost > 0)
                        <div><dt class="text-gray-500">Biaya Perbaikan</dt><dd>{{ rupiah($handling->extra_cost) }} · {{ $handling->cashAccount?->display_name }}</dd></div>
                    @endif
                @endcan
                <div><dt class="text-gray-500">Dicatat oleh</dt><dd>{{ $handling->creator?->name ?? '-' }}</dd></div>
            </dl>
        </x-card>

        <div class="space-y-6 lg:col-span-2">
            <x-card title="Barang" :padding="false">
                <table class="table-base">
                    <thead><tr><th>Produk</th><th class="text-right">Qty</th>@can('costing.view')<th class="text-right">HPP / pcs</th><th class="text-right">Nilai</th>@if ((float) $handling->extra_cost > 0)<th class="text-right">Biaya Perbaikan</th>@endif @endcan</tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($handling->items as $item)
                            <tr>
                                <td><a href="{{ route('stock.products.card', $item->variant) }}" class="text-indigo-600 hover:underline">{{ $item->variant->display_name }}</a><div class="text-xs text-gray-500">{{ $item->variant->sku }}</div></td>
                                <td class="text-right">{{ qty($item->quantity) }} {{ $item->variant->product->unit?->code }}</td>
                                @can('costing.view')
                                    <td class="text-right whitespace-nowrap">{{ rupiah($item->unit_cost) }}</td>
                                    <td class="text-right whitespace-nowrap">{{ rupiah($item->cost) }}</td>
                                    @if ((float) $handling->extra_cost > 0)<td class="text-right whitespace-nowrap">{{ rupiah($item->extra_cost) }}</td>@endif
                                @endcan
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
            <x-journal-entries :sources="$handling" />
            <x-activity-log :activities="$handling->activities" />
        </div>
    </div>
</x-layouts.app>
