@props(['name' => 'warehouse_id', 'label' => 'Gudang', 'value' => null, 'help' => null, 'tip' => 'Gudang tempat stok bertambah atau berkurang untuk dokumen ini. Stok dicek per gudang, jadi pilih gudang tempat barang benar-benar berada.'])

{{-- Pilihan gudang aktif (default = gudang utama). Bila hanya ada satu gudang, cukup input tersembunyi. --}}
@php
    $options = \App\Models\Warehouse::options();
    $value ??= \App\Models\Warehouse::default()->id;
@endphp
@if (count($options) > 1)
    <x-form.select :name="$name" :label="$label" :options="$options" :value="$value" :placeholder="false" :help="$help" :tip="$tip" {{ $attributes }} />
@else
    <input type="hidden" name="{{ $name }}" value="{{ old($name, $value) }}">
@endif
