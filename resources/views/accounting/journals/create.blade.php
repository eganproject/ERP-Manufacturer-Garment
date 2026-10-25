@php
    $initial = old('lines', $lines ?: [
        ['account_id' => '', 'description' => '', 'debit' => '', 'credit' => ''],
        ['account_id' => '', 'description' => '', 'debit' => '', 'credit' => ''],
    ]);
@endphp
<x-layouts.app title="Jurnal Manual">
    <form method="POST" action="{{ route('journals.store') }}" x-data="journalLines(@js(array_values($initial)))" class="space-y-6">
        @csrf

        <x-card title="Informasi Jurnal">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <x-form.input name="date" label="Tanggal" :value="today()" required data-date
                              :help="$lockedUntil ? 'Buku ditutup s.d. '.tanggal($lockedUntil) : null" />
                <x-form.input name="reference" label="No. Bukti / Referensi" help="Opsional, mis. no. kuitansi" />
                <x-form.input name="description" label="Keterangan" :value="$description" required class="md:col-span-2"
                              placeholder="Mis. Saldo awal, biaya listrik Oktober, penyusutan mesin" />
            </div>
        </x-card>

        <x-card title="Baris Jurnal" :padding="false">
            <x-slot:header><button type="button" class="btn-secondary py-1.5" @click="add()">+ Baris</button></x-slot:header>
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th class="min-w-72">Akun</th><th class="min-w-48">Keterangan baris</th><th class="w-44">Debit (Rp)</th><th class="w-44">Kredit (Rp)</th><th class="w-12"></th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-for="(row, index) in rows" :key="row.key">
                            <tr>
                                <td>
                                    <select :name="`lines[${index}][account_id]`" class="form-control" required data-select data-placeholder="Pilih akun..."
                                            x-init="$el.value = row.account_id; $nextTick(() => ERP.initPlugins($el))" @change="row.account_id = $event.target.value">
                                        <option value="">Pilih akun...</option>
                                        @foreach ($accounts as $id => $label)
                                            <option value="{{ $id }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td><input type="text" :name="`lines[${index}][description]`" x-model="row.description" class="form-control"></td>
                                <td>
                                    <input type="text" :name="`lines[${index}][debit]`" class="form-control" inputmode="numeric" data-money
                                           x-init="$el.value = row.debit; $nextTick(() => ERP.initPlugins($el))" @money-change="row.debit = $event.detail">
                                </td>
                                <td>
                                    <input type="text" :name="`lines[${index}][credit]`" class="form-control" inputmode="numeric" data-money
                                           x-init="$el.value = row.credit; $nextTick(() => ERP.initPlugins($el))" @money-change="row.credit = $event.detail">
                                </td>
                                <td class="text-right"><button type="button" class="text-xl text-red-500 hover:text-red-700" @click="remove(index)" x-show="rows.length > 2">&times;</button></td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot>
                        <tr class="bg-gray-50 font-semibold">
                            <td colspan="2" class="px-4 py-3 text-right">Total</td>
                            <td class="px-4 py-3" x-text="money(totalDebit)"></td>
                            <td class="px-4 py-3" x-text="money(totalCredit)"></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td colspan="5" class="px-4 py-2 text-right text-sm">
                                <span x-show="balanced" class="text-emerald-600">Seimbang ✓</span>
                                <span x-show="!balanced" class="text-red-600" x-text="'Selisih ' + money(Math.abs(totalDebit - totalCredit))"></span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-card>

        <div class="flex justify-end gap-2">
            <a href="{{ route('journals.index') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary" :disabled="!balanced">Simpan Jurnal</button>
        </div>
    </form>

    <script>
        function journalLines(rows) {
            let key = 0;
            const blank = { account_id: '', description: '', debit: '', credit: '' };
            return {
                rows: rows.map(r => ({ ...blank, ...r, key: key++ })),
                add() { this.rows.push({ ...blank, key: key++ }); },
                remove(index) { this.rows.splice(index, 1); },
                get totalDebit() { return this.rows.reduce((s, r) => s + (Number(r.debit) || 0), 0); },
                get totalCredit() { return this.rows.reduce((s, r) => s + (Number(r.credit) || 0), 0); },
                get balanced() { return this.totalDebit > 0 && Math.abs(this.totalDebit - this.totalCredit) < 0.005; },
                money(value) { return 'Rp ' + ERP.formatNumber(value); },
            };
        }
    </script>
</x-layouts.app>
