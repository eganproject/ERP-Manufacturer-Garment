@props(['warehouse' => null, 'block' => false])
{{-- Nama gudang dokumen; hanya tampil bila ada lebih dari satu gudang. --}}
@if ($warehouse && \App\Models\Warehouse::isMulti())
    @if ($block)
        <div><dt class="text-gray-500">Gudang</dt><dd class="font-medium">{{ $warehouse->display_name }}</dd></div>
    @else
        <div class="text-xs text-gray-500">{{ $warehouse->display_name }}</div>
    @endif
@endif
