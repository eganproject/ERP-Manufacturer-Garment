{{-- Rincian stok per gudang di bawah angka total ($item, $warehouses, $defect = false). Hanya bila gudang > 1. --}}
@if ($warehouses->count() > 1)
    @php
        $field = ($defect ?? false) ? 'defect_quantity' : 'quantity';
        $parts = $item->warehouseStocks->filter(fn ($s) => (float) $s->{$field} != 0)
            ->map(fn ($s) => ($warehouses->get($s->warehouse_id)?->code ?? '?').' '.qty($s->{$field}));
    @endphp
    @if ($parts->isNotEmpty())
        <div class="text-xs font-normal text-gray-500">{{ $parts->implode(' · ') }}</div>
    @endif
@endif
