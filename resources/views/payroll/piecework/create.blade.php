<x-layouts.app title="Input Hasil Kerja Borongan">
    <x-guide title="Mencatat hasil kerja harian" :steps="['Pilih SPK yang sedang **Dalam Proses**, lalu isi tanggal kerja.', 'Per baris: pilih karyawan, operasi (atau pcs jadi komplit), jumlah **bagus** dan **reject QC**.', 'Upah = bagus × tarif; reject tidak dibayar. Upah langsung menambah HPP SPK dan menunggu dibayar di Penggajian.']"></x-guide>
    <x-card class="mb-6">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div class="w-full sm:w-96">
                <x-form.select name="production_order_id" label="SPK (Dalam Proses)" tip="Hanya SPK yang sudah Mulai Produksi dan belum selesai yang bisa dicatat hasil kerjanya." :value="$spk?->id" placeholder="-- Pilih SPK --" onchange="this.form.submit()"
                    :options="$orders->mapWithKeys(fn ($o) => [$o->id => $o->number.' · '.$o->product->name.' ('.qty($o->quantity).' pcs)'])" />
            </div>
            <noscript><button type="submit" class="btn-secondary">Pilih</button></noscript>
        </form>
        @if ($orders->isEmpty())
            <p class="mt-3 text-sm text-gray-500">Belum ada SPK yang sedang berjalan. Hasil kerja dicatat untuk SPK berstatus Dalam Proses.</p>
        @endif
    </x-card>

    @if ($spk)
        @php
            $operations = $spk->product->operations->where('is_active', true);
            $fullRate = (float) $spk->product->full_garment_rate;
            $options = $operations->mapWithKeys(fn ($op) => [(string) $op->id => ['label' => $op->name.' ('.$op->stage->label().')', 'rate' => (float) $op->rate, 'full' => false]])->all();
            if ($fullRate > 0) {
                $options['full'] = ['label' => 'Pcs jadi (komplit)', 'rate' => $fullRate, 'full' => true];
            }
            $employeeTypes = $employees->mapWithKeys(fn ($e) => [$e->id => $e->work_type->value]);
            $initial = old('lines', [['employee_id' => '', 'operation' => '', 'quantity' => '', 'reject_quantity' => '']]);
        @endphp

        @if (empty($options))
            <div class="rounded-lg bg-amber-50 p-4 text-sm text-amber-800">
                Artikel {{ $spk->product->name }} belum punya tarif borongan.
                @can('employees.manage')<a href="{{ route('piece-rates.edit', $spk->product) }}" class="font-medium underline">Atur tarif borongan</a> terlebih dahulu.@endcan
            </div>
        @else
            <div class="grid grid-cols-1 gap-6 xl:grid-cols-3">
                <form method="POST" action="{{ route('piecework.store') }}" class="xl:col-span-2"
                      x-data="{
                          rows: @js(array_values($initial)),
                          options: @js($options),
                          types: @js($employeeTypes),
                          add() { this.rows.push({ employee_id: '', operation: '', quantity: '', reject_quantity: '' }); },
                          allowed(row, key) {
                              const t = this.types[row.employee_id];
                              if (!t) return true;
                              return this.options[key].full ? t !== 'operation' : t !== 'full';
                          },
                          amount(row) { const o = this.options[row.operation]; return o ? (Number(row.quantity) || 0) * o.rate : 0; },
                          get total() { return this.rows.reduce((s, r) => s + this.amount(r), 0); },
                          fmt(v) { return 'Rp ' + ERP.formatNumber(v); },
                      }">
                    @csrf
                    <input type="hidden" name="production_order_id" value="{{ $spk->id }}">
                    <x-card title="Hasil Kerja {{ $spk->number }}" :padding="false">
                        <div class="grid grid-cols-1 gap-4 border-b border-gray-100 p-4 sm:grid-cols-3">
                            <x-form.input name="date" label="Tanggal Kerja" tip="Tanggal pekerjaan dilakukan. Menentukan periode penggajian yang akan membayarnya." :value="old('date', now()->toDateString())" required data-date />
                            <x-form.input name="notes" label="Catatan" :value="old('notes')" placeholder="mis. Line 2, shift pagi" class="sm:col-span-2" />
                        </div>
                        <div class="overflow-x-auto">
                            <table class="table-base">
                                <thead><tr><th class="w-8">#</th><th>Karyawan</th><th>Operasi / Segmen</th><th class="w-28 text-right">Bagus <x-tip title="Bagus" text="Jumlah pcs yang lolos QC. Hanya ini yang dibayar. Maksimal sebesar sisa qty operasi di SPK." /></th><th class="w-28 text-right">Reject QC <x-tip title="Reject QC" text="Jumlah pcs yang ditolak QC. Dicatat untuk evaluasi kualitas, tidak dibayar." /></th><th class="w-36 text-right">Upah</th><th class="w-10"></th></tr></thead>
                                <tbody class="divide-y divide-gray-100">
                                    <template x-for="(row, i) in rows" :key="i">
                                        <tr>
                                            <td class="text-gray-500" x-text="i + 1"></td>
                                            <td>
                                                <select class="form-control min-w-48" :name="`lines[${i}][employee_id]`" x-model="row.employee_id">
                                                    <option value="">-- Pilih --</option>
                                                    @foreach ($employees as $employee)
                                                        <option value="{{ $employee->id }}">{{ $employee->name }} ({{ $employee->code }})</option>
                                                    @endforeach
                                                </select>
                                            </td>
                                            <td>
                                                <select class="form-control min-w-60" :name="`lines[${i}][operation]`" x-model="row.operation">
                                                    <option value="">-- Pilih --</option>
                                                    <template x-for="(opt, key) in options" :key="key">
                                                        <option :value="key" :disabled="!allowed(row, key)" :selected="row.operation == key" x-text="opt.label + ' · ' + fmt(opt.rate)"></option>
                                                    </template>
                                                </select>
                                            </td>
                                            <td><input type="number" min="0" step="1" class="form-control text-right" :name="`lines[${i}][quantity]`" x-model="row.quantity"></td>
                                            <td><input type="number" min="0" step="1" class="form-control text-right" :name="`lines[${i}][reject_quantity]`" x-model="row.reject_quantity"></td>
                                            <td class="text-right whitespace-nowrap" x-text="fmt(amount(row))"></td>
                                            <td class="text-right"><button type="button" class="text-red-600 hover:text-red-800" @click="rows.splice(i, 1)" x-show="rows.length > 1">&times;</button></td>
                                        </tr>
                                    </template>
                                </tbody>
                                <tfoot class="bg-gray-50">
                                    <tr>
                                        <td colspan="5" class="px-4 py-3"><button type="button" class="text-sm font-medium text-indigo-600 hover:underline" @click="add()">+ Tambah baris</button></td>
                                        <td class="px-4 py-3 text-right font-semibold" x-text="fmt(total)"></td>
                                        <td></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        @error('lines')<p class="px-4 pt-3 text-sm text-red-600">{{ $message }}</p>@enderror
                        <p class="px-4 pt-3 text-xs text-gray-500">Upah dihitung dari hasil bagus × tarif. Reject QC dicatat untuk mengurangi sisa SPK tetapi tidak dibayar. Total upah langsung masuk HPP SPK.</p>
                        <div class="mt-4 flex items-center justify-end gap-2 border-t border-gray-200 p-4">
                            <a href="{{ route('piecework.index') }}" class="btn-secondary">Batal</a>
                            <button type="submit" class="btn-primary">Simpan Hasil Kerja</button>
                        </div>
                    </x-card>
                </form>

                <x-card title="Progres Borongan SPK" :padding="false">
                    <table class="table-base">
                        <thead><tr><th>Operasi</th><th class="text-right">Selesai</th><th class="text-right">Sisa</th></tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($progress as $key => $row)
                                @continue($key === 'full' && $fullRate <= 0)
                                <tr>
                                    <td>{{ $row['name'] }}@if ($row['stage'])<div class="text-xs text-gray-500">{{ $row['stage'] }}</div>@endif</td>
                                    <td class="text-right whitespace-nowrap">{{ qty($row['good']) }}@if ($row['reject'] > 0) <span class="text-xs text-red-600">+{{ qty($row['reject']) }} rj</span>@endif<div class="text-xs text-gray-500">dari {{ qty($row['capacity']) }}</div></td>
                                    <td @class(['text-right font-medium', 'text-emerald-600' => $row['remaining'] <= 0])>{{ qty($row['remaining']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if ($spk->stages->contains(fn ($s) => $s->subcontractOrder))
                        <p class="border-t border-gray-200 p-4 text-xs text-gray-500">Kapasitas dikurangi pcs yang tahapnya dimaklonkan ke vendor.</p>
                    @endif
                </x-card>
            </div>
        @endif
    @endif
</x-layouts.app>
