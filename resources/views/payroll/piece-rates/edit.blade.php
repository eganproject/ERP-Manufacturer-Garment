@php
    $initial = old('operations', $product->operations->map(fn ($op) => [
        'id' => $op->id, 'name' => $op->name, 'stage' => $op->stage->value, 'rate' => (float) $op->rate,
        'is_active' => $op->is_active, 'used' => $op->entries_count > 0,
    ])->values()->all());
@endphp
<x-layouts.app :title="'Tarif Borongan '.$product->name">
    <x-guide title="Mengatur tarif upah borongan" :steps="['Isi daftar **operasi/segmen** (mis. potong, pasang lengan, obras) beserta tarif per pcs dan tahap SPK-nya.', 'Isi **tarif per pcs jadi** untuk karyawan yang mengerjakan satu baju dari awal sampai jadi.', 'Upah = jumlah bagus × tarif. Artikel yang punya tarif borongan tidak lagi dibebani upah standar BOM.']"></x-guide>
    <x-slot:actions>
        <a href="{{ route('piece-rates.index') }}" class="btn-secondary">Semua Artikel</a>
    </x-slot:actions>

    <form method="POST" action="{{ route('piece-rates.update', $product) }}"
          x-data="{
              rows: @js(array_values($initial)),
              full: @js((float) old('full_garment_rate', $product->full_garment_rate)),
              add() { this.rows.push({ id: null, name: '', stage: 'sewing', rate: '', is_active: true, used: false }); },
              get total() { return this.rows.filter(r => r.is_active && r.name).reduce((s, r) => s + (Number(r.rate) || 0), 0); },
              fmt(v) { return 'Rp ' + ERP.formatNumber(v); },
          }">
        @csrf
        @method('PUT')
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <x-card title="Operasi / Segmen (tarif per pcs)" :padding="false" class="lg:col-span-2">
                <div class="overflow-x-auto">
                    <table class="table-base">
                        <thead><tr><th class="w-10">#</th><th>Nama Operasi</th><th class="w-48">Tahap SPK <x-tip title="Tahap SPK" text="Tahap produksi tempat operasi ini dikerjakan. Bila tahap itu dimaklonkan, operasi ini tidak bisa dicatat untuk qty yang dimaklonkan." /></th><th class="w-40 text-right">Tarif / pcs (Rp)</th><th class="w-20 text-center">Aktif <x-tip title="Aktif" text="Matikan operasi yang tidak dipakai lagi. Operasi yang sudah punya hasil kerja tidak bisa dihapus, cukup dinonaktifkan." /></th><th class="w-12"></th></tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            <template x-for="(row, i) in rows" :key="i">
                                <tr>
                                    <td class="text-gray-500" x-text="i + 1"></td>
                                    <td>
                                        <input type="hidden" :name="`operations[${i}][id]`" :value="row.id ?? ''">
                                        <input type="text" class="form-control" :name="`operations[${i}][name]`" x-model="row.name" placeholder="mis. Pasang lengan">
                                    </td>
                                    <td>
                                        <select class="form-control" :name="`operations[${i}][stage]`" x-model="row.stage">
                                            @foreach ($stages as $value => $label)
                                                <option value="{{ $value }}">{{ $label }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td><input type="number" min="0" step="1" class="form-control text-right" :name="`operations[${i}][rate]`" x-model="row.rate"></td>
                                    <td class="text-center">
                                        <input type="hidden" :name="`operations[${i}][is_active]`" :value="row.is_active ? 1 : 0">
                                        <input type="checkbox" class="rounded border-gray-300 text-indigo-600" x-model="row.is_active">
                                    </td>
                                    <td class="text-right">
                                        <button type="button" class="text-red-600 hover:text-red-800 disabled:opacity-30" :disabled="row.used" :title="row.used ? 'Sudah dipakai di hasil kerja, nonaktifkan saja' : 'Hapus'" @click="rows.splice(i, 1)">&times;</button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot class="bg-gray-50">
                            <tr>
                                <td colspan="3" class="px-4 py-3"><button type="button" class="text-sm font-medium text-indigo-600 hover:underline" @click="add()">+ Tambah operasi</button></td>
                                <td class="px-4 py-3 text-right font-semibold" x-text="fmt(total)"></td>
                                <td colspan="2" class="px-4 py-3 text-xs text-gray-500">total / pcs</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                @error('operations')<p class="px-4 pb-3 text-sm text-red-600">{{ $message }}</p>@enderror
            </x-card>

            <div class="space-y-6">
                <x-card title="Tarif pcs Jadi (Komplit)">
                    <x-form.label for="f_full" label="Tarif per pcs jadi (Rp)" tip="Upah untuk satu baju yang dikerjakan komplit oleh satu orang. 1 pcs jadi dihitung menyelesaikan semua operasi." />
                    <input id="f_full" type="number" min="0" step="1" name="full_garment_rate" x-model="full" class="form-control text-right">
                    <p class="mt-2 text-xs text-gray-500">Untuk karyawan yang mengerjakan satu baju dari potong sampai jadi. Total tarif operasi saat ini <span class="font-medium" x-text="fmt(total)"></span>. Isi 0 bila artikel ini tidak dikerjakan komplit.</p>
                </x-card>
                <x-card title="Pembanding">
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-gray-500">Upah standar BOM</dt><dd>{{ $product->activeBom ? rupiah($product->activeBom->labor_cost) : '-' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Total tarif operasi</dt><dd x-text="fmt(total)"></dd></div>
                    </dl>
                    <p class="mt-3 text-xs text-gray-500">Perubahan tarif hanya berlaku untuk hasil kerja yang dicatat setelah disimpan.</p>
                </x-card>
                <div class="flex justify-end"><button type="submit" class="btn-primary">Simpan Tarif</button></div>
            </div>
        </div>
    </form>
</x-layouts.app>
