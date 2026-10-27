@use('App\Enums\DefectHandlingType')
@php
    $initialRows = old('lines', $initial ?? [['product_variant_id' => '', 'quantity' => '']]);
@endphp
<x-layouts.app :title="$type->label()">
    <div class="mb-4 flex flex-wrap gap-2">
        @foreach (DefectHandlingType::cases() as $option)
            <a href="{{ route('defects.create', ['type' => $option->value]) }}"
               @class(['rounded-full border px-3 py-1 text-sm', 'border-indigo-500 bg-indigo-50 font-medium text-indigo-700' => $option === $type, 'border-gray-200 text-gray-600 hover:border-indigo-300' => $option !== $type])>{{ $option->label() }}</a>
        @endforeach
    </div>

    <form method="POST" action="{{ route('defects.store') }}" class="space-y-6" x-data="defectLines(@js(array_values($initialRows)))"
          data-confirm="{{ $type->label() }} akan dicatat, stok & jurnal diperbarui." data-confirm-icon="question" data-confirm-button="Ya, simpan">
        @csrf
        <input type="hidden" name="type" value="{{ $type->value }}">

        <x-card :title="$type->label()">
            <p class="mb-4 text-sm text-gray-500">{{ $type->hint() }}</p>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <x-form.input name="date" label="Tanggal" :value="today()" tip="Tanggal penanganan barang cacat. Tercatat di kartu stok dan jurnal." required data-date />
                <x-form.warehouse />
                <x-form.input name="notes" label="Catatan" class="md:col-span-2"
                              :placeholder="match ($type) { DefectHandlingType::Rework => 'Mis. jahit ulang kerah, ganti kancing', DefectHandlingType::Scrap => 'Mis. noda permanen, sobek', DefectHandlingType::Quarantine => 'Mis. temuan QC gudang', default => 'Mis. hasil cek ulang layak jual' }" />
                @if ($type === DefectHandlingType::Rework)
                    <x-form.input name="extra_cost" label="Biaya Perbaikan (Rp)" data-money inputmode="numeric" help="Opsional. Dibagi rata per pcs dan menambah HPP barang." tip="Biaya tunai untuk memperbaiki barang (mis. ongkos jahit ulang). Dibagi rata ke semua pcs dan menambah harga pokok barang tersebut." />
                    <x-form.select name="cash_account_id" label="Dibayar dari Rekening" tip="Rekening kas/bank yang dipakai membayar biaya perbaikan. Saldonya akan berkurang." :options="$cashAccounts" data-select help="Wajib bila ada biaya perbaikan." />
                @endif
            </div>
        </x-card>

        <x-card title="Barang" :padding="false">
            <x-slot:header><button type="button" class="btn-secondary btn-sm" @click="add()">+ Baris</button></x-slot:header>
            @if ($variants->isEmpty())
                <p class="p-5 text-sm text-gray-500">{{ $type->fromDefect() ? 'Tidak ada stok cacat yang bisa diproses.' : 'Tidak ada stok barang jadi.' }}</p>
            @endif
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th class="min-w-96">Produk ({{ $type->fromDefect() ? 'dari stok cacat' : 'dari stok jual' }})</th><th class="w-48">Jumlah (pcs)</th><th class="w-12"></th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-for="(row, index) in rows" :key="row.key">
                            <tr>
                                <td>
                                    <select :name="`lines[${index}][product_variant_id]`" class="form-control" required data-select data-placeholder="Pilih produk..."
                                            x-init="$el.value = row.product_variant_id; $nextTick(() => ERP.initPlugins($el))">
                                        <option value="">Pilih produk...</option>
                                        @foreach ($variants as $id => $label)
                                            <option value="{{ $id }}">{{ $label }}</option>
                                        @endforeach
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
            <a href="{{ route('defects.index') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary">Simpan</button>
        </div>
    </form>

    <script>
        function defectLines(rows) {
            let key = 0;
            const blank = { product_variant_id: '', quantity: '' };
            return {
                rows: rows.map(r => ({ ...blank, ...r, key: key++ })),
                add() { this.rows.push({ ...blank, key: key++ }); },
                remove(index) { this.rows.splice(index, 1); },
            };
        }
    </script>
</x-layouts.app>
