<x-layouts.app :title="$warehouse->exists ? 'Ubah Gudang' : 'Tambah Gudang'">
    <x-card class="max-w-2xl">
        <form method="POST" action="{{ $warehouse->exists ? route('warehouses.update', $warehouse) : route('warehouses.store') }}" class="space-y-4">
            @csrf
            @if ($warehouse->exists) @method('PUT') @endif
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-form.input name="code" label="Kode" :value="$warehouse->code" required placeholder="mis. GJ" />
                <x-form.input name="name" label="Nama Gudang" :value="$warehouse->name" required placeholder="mis. Gudang Barang Jadi" class="sm:col-span-2" />
            </div>
            <x-form.textarea name="address" label="Alamat / Lokasi" :value="$warehouse->address" rows="2" />
            <x-form.textarea name="notes" label="Catatan" :value="$warehouse->notes" rows="2" />
            <div class="flex flex-wrap gap-6">
                <x-form.checkbox name="is_active" label="Aktif" :checked="$warehouse->is_active" />
                <x-form.checkbox name="is_default" label="Jadikan gudang default" :checked="$warehouse->is_default" />
            </div>
            <x-form.actions :cancel="route('warehouses.index')" />
        </form>
        @if ($warehouse->exists && ! $warehouse->is_default)
            @can('warehouses.manage')
                <div class="mt-6 border-t border-gray-100 pt-4"><x-delete-button :action="route('warehouses.destroy', $warehouse)" class="btn-secondary !text-red-600" /></div>
            @endcan
        @endif
    </x-card>
</x-layouts.app>
