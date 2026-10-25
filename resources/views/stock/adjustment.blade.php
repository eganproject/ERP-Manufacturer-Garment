<x-layouts.app title="Penyesuaian Stok">
    <x-card class="max-w-2xl" title="Koreksi stok hasil stock opname, barang rusak, dll.">
        <form method="POST" action="{{ route('stock.adjustment.store') }}" class="space-y-4"
              data-confirm="Stok akan diubah dan tercatat di kartu stok." data-confirm-icon="question" data-confirm-button="Ya, simpan">
            @csrf
            <x-form.select name="item" label="Barang" required data-select data-placeholder="Cari bahan / SKU...">
                <optgroup label="Bahan Baku">
                    @foreach ($materials as $m)
                        <option value="material:{{ $m->id }}" @selected(old('item') === 'material:'.$m->id)>{{ $m->display_name }} — stok {{ qty($m->stock) }} {{ $m->unit->code }}</option>
                    @endforeach
                </optgroup>
                <optgroup label="Barang Jadi">
                    @foreach ($variants as $v)
                        <option value="variant:{{ $v->id }}" @selected(old('item') === 'variant:'.$v->id)>{{ $v->sku }} · {{ $v->display_name }} — stok {{ qty($v->stock) }}</option>
                    @endforeach
                </optgroup>
            </x-form.select>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-form.input name="date" label="Tanggal" :value="now()" required data-date />
                <x-form.warehouse />
                <x-form.select name="mode" label="Jenis" :options="['in' => 'Tambah (+)', 'out' => 'Kurangi (−)', 'set' => 'Set stok fisik (opname)']" value="set" required :placeholder="false" />
                <x-form.input name="quantity" type="number" step="0.01" min="0" label="Jumlah" required />
            </div>
            <x-form.input name="notes" label="Alasan" required placeholder="mis. Hasil stock opname Oktober" />
            <x-form.actions :cancel="route('stock.materials')" />
        </form>
    </x-card>
</x-layouts.app>
