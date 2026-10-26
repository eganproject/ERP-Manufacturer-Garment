@use('App\Enums\CashTransactionType')
@php
    $isTransfer = $type === CashTransactionType::Transfer;
    $isIn = $type === CashTransactionType::In;
    $initial = old('lines', [['account_id' => '', 'description' => '', 'amount' => '']]);
@endphp
<x-layouts.app :title="$type->label()">
    <div class="mb-4 flex flex-wrap gap-2">
        @foreach (CashTransactionType::cases() as $option)
            <a href="{{ route('cash-transactions.create', ['type' => $option->value]) }}"
               @class(['rounded-full border px-3 py-1 text-sm', 'border-indigo-500 bg-indigo-50 font-medium text-indigo-700' => $option === $type, 'border-gray-200 text-gray-600 hover:border-indigo-300' => $option !== $type])>{{ $option->label() }}</a>
        @endforeach
    </div>

    <form method="POST" action="{{ route('cash-transactions.store') }}" class="space-y-6" x-data="cashLines(@js(array_values($initial)))"
          data-confirm="{{ $type->label() }} akan dicatat dan dijurnal." data-confirm-icon="question" data-confirm-button="Ya, simpan">
        @csrf
        <input type="hidden" name="type" value="{{ $type->value }}">

        <x-card :title="$type->label()">
            <p class="mb-4 text-sm text-gray-500">{{ $type->hint() }}</p>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <x-form.input name="date" label="Tanggal" :value="today()" required data-date tip="Tanggal uang masuk/keluar sesuai mutasi rekening atau kuitansi." />
                <x-form.select name="cash_account_id" :label="$isTransfer ? 'Dari Rekening' : ($isIn ? 'Masuk ke Rekening' : 'Keluar dari Rekening')" :options="$cashAccounts" :value="$defaultAccount" required data-select tip="Rekening kas/bank yang saldonya berubah. Rekening baru ditambahkan di Bagan Akun (centang Rekening kas / bank)." />
                @if ($isTransfer)
                    <x-form.select name="to_account_id" label="Ke Rekening" tip="Rekening tujuan transfer, mis. tarik tunai dari bank ke kas kecil atau setor kas ke bank. Total kas perusahaan tidak berubah." :options="$cashAccounts" required data-select />
                    <x-form.input name="amount" label="Jumlah (Rp)" required data-money inputmode="numeric" />
                @else
                    <x-form.input name="party_name" tip="Nama orang/instansi pemberi atau penerima uang. Dicetak di bukti kas (opsional)." :label="$isIn ? 'Diterima dari' : 'Dibayar ke'" placeholder="Opsional, mis. PLN, Bank BRI, pemilik" />
                @endif
                <x-form.input name="reference" label="No. Referensi" placeholder="No. kuitansi / transfer / cek" tip="Nomor bukti dari luar (kuitansi, struk, bukti transfer) untuk memudahkan pencocokan." />
                <x-form.input name="description" label="Keterangan" required :class="$isTransfer ? 'md:col-span-1' : 'md:col-span-2'"
                              :placeholder="$isTransfer ? 'Mis. Setor tunai ke BCA' : ($isIn ? 'Mis. Setoran modal pemilik' : 'Mis. Bayar listrik pabrik Oktober')" />
            </div>
        </x-card>

        @unless ($isTransfer)
            <x-card :title="$isIn ? 'Sumber Penerimaan (akun kredit)' : 'Penggunaan Dana (akun debit)'" :padding="false" :tip="$isIn ? 'Pilih akun yang menjelaskan asal uang, mis. Pendapatan Lain-lain, Modal Pemilik, Pinjaman Bank. Boleh lebih dari satu baris.' : 'Pilih akun biaya atau tujuan uang, mis. Beban Listrik, Beban Sewa, Hutang PPN. Boleh lebih dari satu baris bila satu kuitansi untuk beberapa keperluan.'">
                <x-slot:header><button type="button" class="btn-secondary py-1.5" @click="add()">+ Baris</button></x-slot:header>
                <div class="overflow-x-auto">
                    <table class="table-base">
                        <thead><tr><th class="min-w-72">Akun</th><th class="min-w-48">Keterangan baris</th><th class="w-48">Jumlah (Rp)</th><th class="w-12"></th></tr></thead>
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
                                        <input type="text" :name="`lines[${index}][amount]`" class="form-control" inputmode="numeric" data-money required
                                               x-init="$el.value = row.amount; $nextTick(() => ERP.initPlugins($el))" @money-change="row.amount = $event.detail">
                                    </td>
                                    <td class="text-right"><button type="button" class="text-xl text-red-500 hover:text-red-700" @click="remove(index)" x-show="rows.length > 1">&times;</button></td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot>
                            <tr class="bg-gray-50 font-semibold">
                                <td colspan="2" class="px-4 py-3 text-right">Total {{ strtolower($type->label()) }}</td>
                                <td class="px-4 py-3" x-text="'Rp ' + ERP.formatNumber(total)"></td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                @error('lines')<p class="px-4 pb-3 text-sm text-red-600">{{ $message }}</p>@enderror
            </x-card>
        @endunless

        <div class="flex justify-end gap-2">
            <a href="{{ route('cash-bank.index') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary">Simpan</button>
        </div>
    </form>

    <script>
        function cashLines(rows) {
            let key = 0;
            const blank = { account_id: '', description: '', amount: '' };
            return {
                rows: rows.map(r => ({ ...blank, ...r, key: key++ })),
                add() { this.rows.push({ ...blank, key: key++ }); },
                remove(index) { this.rows.splice(index, 1); },
                get total() { return this.rows.reduce((s, r) => s + (Number(r.amount) || 0), 0); },
            };
        }
    </script>
</x-layouts.app>
