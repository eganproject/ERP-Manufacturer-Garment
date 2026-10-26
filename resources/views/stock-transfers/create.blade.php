@php
    $initialRows = old('lines', [['item' => '', 'quantity' => '']]);
    $options = \App\Models\Warehouse::options();
    $to = old('to_warehouse_id', collect($options)->keys()->first(fn ($id) => $id != $from));
@endphp
<x-layouts.app title="Transfer Antar Gudang">
    <x-guide title="Memindahkan stok antar gudang" :steps="['Pilih gudang asal dan tujuan, lalu isi barang dan jumlahnya. Pilihan barang menampilkan stok di gudang asal.', 'Transfer hanya memindahkan lokasi stok; nilai persediaan dan jurnal tidak berubah.', 'Cetak surat transfer sebagai bukti serah terima antar gudang.']"></x-guide>
    @if (count($options) < 2)
        <x-card><p class="text-sm text-gray-600">Baru ada satu gudang aktif. @can('warehouses.manage')<a href="{{ route('warehouses.create') }}" class="text-indigo-600 hover:underline">Tambah gudang</a> terlebih dulu.@endcan</p></x-card>
    @else
        <form method="POST" action="{{ route('stock-transfers.store') }}" class="space-y-6" x-data="transferLines(@js(array_values($initialRows)))"
              data-confirm="Stok akan dipindahkan antar gudang." data-confirm-icon="question" data-confirm-button="Ya, transfer">
            @csrf
            <x-card title="Informasi Transfer">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <x-form.input name="date" label="Tanggal" :value="today()" required data-date />
                    <x-form.select name="from_warehouse_id" label="Dari Gudang" tip="Gudang asal barang. Stok gudang ini berkurang; mengganti pilihan akan memuat ulang daftar stok." :options="$options" :value="$from" :placeholder="false" required
                                   onchange="window.location = '{{ route('stock-transfers.create') }}?from_warehouse_id=' + this.value" />
                    <x-form.select name="to_warehouse_id" label="Ke Gudang" tip="Gudang tujuan. Stok gudang ini bertambah." :options="$options" :value="$to" :placeholder="false" required />
                    <x-form.input name="notes" label="Catatan" placeholder="mis. Stok untuk pengiriman cabang" />
                </div>
            </x-card>

            <x-card title="Barang" :padding="false">
                <x-slot:header><button type="button" class="btn-secondary py-1.5" @click="add()">+ Baris</button></x-slot:header>
                <div class="overflow-x-auto">
                    <table class="table-base">
                        <thead><tr><th class="min-w-96">Barang (stok di gudang asal)</th><th class="w-48">Jumlah</th><th class="w-12"></th></tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            <template x-for="(row, index) in rows" :key="row.key">
                                <tr>
                                    <td>
                                        <select :name="`lines[${index}][item]`" class="form-control" required data-select data-placeholder="Cari bahan / SKU..."
                                                x-init="$el.value = row.item; $nextTick(() => ERP.initPlugins($el))">
                                            <option value="">Cari bahan / SKU...</option>
                                            <optgroup label="Bahan Baku">
                                                @foreach ($materials as $m)
                                                    @if (($s = $m->stockIn($from)) > 0)<option value="material:{{ $m->id }}">{{ $m->display_name }} — {{ qty($s) }} {{ $m->unit->code }}</option>@endif
                                                @endforeach
                                            </optgroup>
                                            <optgroup label="Barang Jadi">
                                                @foreach ($variants as $v)
                                                    @if (($s = $v->stockIn($from)) > 0)<option value="variant:{{ $v->id }}">{{ $v->sku }} · {{ $v->display_name }} — {{ qty($s) }} pcs</option>@endif
                                                @endforeach
                                            </optgroup>
                                        </select>
                                    </td>
                                    <td><input type="number" step="any" min="0" :name="`lines[${index}][quantity]`" x-model="row.quantity" class="form-control" required></td>
                                    <td class="text-right"><button type="button" class="text-xl text-red-500 hover:text-red-700" @click="remove(index)" x-show="rows.length > 1">&times;</button></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
                @error('lines')<p class="px-4 pb-3 text-sm text-red-600">{{ $message }}</p>@enderror
                @error('stock')<p class="px-4 pb-3 text-sm text-red-600">{{ $message }}</p>@enderror
            </x-card>

            <div class="flex justify-end gap-2">
                <a href="{{ route('stock-transfers.index') }}" class="btn-secondary">Batal</a>
                <button type="submit" class="btn-primary">Simpan Transfer</button>
            </div>
        </form>

        <script>
            function transferLines(rows) {
                let key = 0;
                const blank = { item: '', quantity: '' };
                return {
                    rows: rows.map(r => ({ ...blank, ...r, key: key++ })),
                    add() { this.rows.push({ ...blank, key: key++ }); },
                    remove(index) { this.rows.splice(index, 1); },
                };
            }
        </script>
    @endif
</x-layouts.app>
