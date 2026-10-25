<x-layouts.app :title="$unit->exists ? 'Ubah Satuan' : 'Tambah Satuan'">
    <x-card class="max-w-xl">
        <form method="POST" action="{{ $unit->exists ? route('units.update', $unit) : route('units.store') }}" class="space-y-4">
            @csrf
            @if ($unit->exists) @method('PUT') @endif
            <x-form.input name="code" label="Kode" :value="$unit->code" required placeholder="mis. PCS, M, KG" />
            <x-form.input name="name" label="Nama" :value="$unit->name" required placeholder="mis. Pieces, Meter" />
            <x-form.actions :cancel="route('units.index')" />
        </form>
    </x-card>
</x-layouts.app>
