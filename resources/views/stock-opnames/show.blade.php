@php
    $items = $opname->items->sortBy(fn ($i) => \App\Services\WarehouseStockService::itemName($i->stockable));
    $counted = $opname->items->whereNotNull('counted_quantity');
    $editable = $opname->isDraft() && auth()->user()->can('stock.adjust');
    $unit = fn ($i) => $i->stockable_type === 'material' ? $i->stockable?->unit?->code : 'pcs';
@endphp
<x-layouts.app :title="'Stock Opname '.$opname->number">
    <x-slot:actions>
        <a href="{{ route('stock-opnames.pdf', $opname) }}" target="_blank" class="btn-secondary">{{ $opname->isDraft() ? 'Cetak Lembar Hitung' : 'Cetak Berita Acara' }}</a>
        @if ($editable)
            <x-reason-form :action="route('stock-opnames.cancel', $opname)" title="Batalkan stock opname?" text="Lembar hitung dibatalkan, stok tidak berubah." button="Ya, batalkan" class="btn-secondary !text-red-600">Batalkan</x-reason-form>
        @endif
    </x-slot:actions>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
        <x-card title="Informasi">
            <dl class="space-y-3 text-sm">
                <div><dt class="text-gray-500">Status</dt><dd><x-status-badge :status="$opname->status" />@if ($opname->cancel_reason)<div class="mt-1 text-gray-600">{{ $opname->cancel_reason }}</div>@endif</dd></div>
                <div><dt class="text-gray-500">Gudang</dt><dd class="font-medium">{{ $opname->warehouse->name }}</dd></div>
                <div><dt class="text-gray-500">Tanggal</dt><dd>{{ tanggal($opname->date) }}</dd></div>
                <div><dt class="text-gray-500">Jenis Barang</dt><dd>{{ $opname->scopeLabel() }}</dd></div>
                <div><dt class="text-gray-500">Sudah dihitung</dt><dd>{{ $counted->count() }} / {{ $opname->items->count() }} barang</dd></div>
                @unless ($opname->isDraft())
                    <div><dt class="text-gray-500">Barang selisih</dt><dd>{{ $opname->items->where('difference', '!=', 0)->count() }}</dd></div>
                    @can('costing.view')
                        <div><dt class="text-gray-500">Nilai selisih</dt><dd @class(['font-medium', 'text-red-600' => $opname->items->sum('value') < 0])>{{ rupiah($opname->items->sum('value')) }}</dd></div>
                    @endcan
                @endunless
                <div><dt class="text-gray-500">Dibuat oleh</dt><dd>{{ $opname->creator?->name ?? '-' }}</dd></div>
                @if ($opname->posted_at)<div><dt class="text-gray-500">Diposting</dt><dd>{{ $opname->poster?->name ?? '-' }} · {{ $opname->posted_at->format('d/m/Y H:i') }}</dd></div>@endif
                @if ($opname->notes)<div><dt class="text-gray-500">Catatan</dt><dd>{{ $opname->notes }}</dd></div>@endif
            </dl>
        </x-card>

        <form method="POST" action="{{ route('stock-opnames.update', $opname) }}" class="lg:col-span-3" x-data>
            @csrf @method('PUT')
            <input type="hidden" name="post" value="0" x-ref="post">
            <x-card title="Hasil Hitung" :padding="false">
                @if ($opname->isDraft())
                    <x-slot:header><span class="text-xs text-gray-500">Kosongkan bila belum dihitung. Barang yang tidak dihitung tidak disesuaikan.</span></x-slot:header>
                @endif
                <div class="overflow-x-auto">
                    <table class="table-base">
                        <thead>
                            <tr>
                                <th>Barang</th>
                                <th class="text-right">Tercatat</th>
                                <th class="w-36 text-right">Fisik</th>
                                <th class="text-right">Selisih</th>
                                @can('costing.view')<th class="text-right">Nilai</th>@endcan
                                <th class="w-56">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($items as $line)
                                @php
                                    $diff = $opname->isDraft() ? ($line->isCounted() ? (float) $line->counted_quantity - (float) $line->system_quantity : 0) : (float) $line->difference;
                                @endphp
                                <tr>
                                    <td>{{ \App\Services\WarehouseStockService::itemName($line->stockable) }}</td>
                                    <td class="text-right whitespace-nowrap">{{ qty($line->system_quantity) }} {{ $unit($line) }}</td>
                                    <td class="text-right">
                                        @if ($editable)
                                            <input type="number" step="any" min="0" name="counts[{{ $line->id }}]" value="{{ old('counts.'.$line->id, $line->counted_quantity !== null ? (float) $line->counted_quantity : '') }}" class="form-control text-right">
                                            @error('counts.'.$line->id)<p class="text-xs text-red-600">{{ $message }}</p>@enderror
                                        @else
                                            {{ $line->isCounted() ? qty($line->counted_quantity) : '-' }}
                                        @endif
                                    </td>
                                    <td @class(['text-right whitespace-nowrap', 'text-red-600' => $diff < 0, 'text-emerald-700' => $diff > 0])>{{ $line->isCounted() ? ($diff > 0 ? '+' : '').qty($diff) : '-' }}</td>
                                    @can('costing.view')
                                        <td class="text-right whitespace-nowrap">{{ $line->isCounted() && $diff != 0 ? rupiah($opname->isDraft() ? $diff * (float) $line->unit_cost : $line->value) : '-' }}</td>
                                    @endcan
                                    <td>
                                        @if ($editable)
                                            <input type="text" name="item_notes[{{ $line->id }}]" value="{{ old('item_notes.'.$line->id, $line->notes) }}" class="form-control" placeholder="mis. rusak, salah taruh">
                                        @else
                                            {{ $line->notes }}
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @error('status')<p class="px-4 pb-3 text-sm text-red-600">{{ $message }}</p>@enderror
                @error('stock')<p class="px-4 pb-3 text-sm text-red-600">{{ $message }}</p>@enderror
            </x-card>

            @if ($editable)
                <div class="mt-4 flex justify-end gap-2">
                    <button type="submit" class="btn-secondary" @click="$refs.post.value = 0">Simpan Hasil Hitung</button>
                    <button type="submit" class="btn-primary" @click="$refs.post.value = 1"
                            data-confirm="Selisih barang yang sudah dihitung akan disesuaikan ke stok gudang {{ $opname->warehouse->name }} dan dijurnal. Lanjutkan?" data-confirm-icon="warning" data-confirm-button="Ya, posting">Posting Opname</button>
                </div>
            @endif
        </form>

        <x-activity-log :activities="$opname->activities" class="lg:col-span-4" />
    </div>
</x-layouts.app>
