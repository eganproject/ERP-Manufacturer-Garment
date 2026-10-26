@use('App\Enums\AccountType')
@use('App\Enums\CashFlowActivity')
<x-layouts.app :title="$account->exists ? 'Ubah Akun '.$account->code : 'Tambah Akun'">
    <x-card class="max-w-3xl">
        <form method="POST" action="{{ $account->exists ? route('accounts.update', $account) : route('accounts.store') }}">
            @csrf
            @if ($account->exists) @method('PUT') @endif

            @if ($locked)
                <div class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
                    {{ $account->is_system ? 'Akun sistem dipakai jurnal otomatis' : 'Akun sudah dipakai jurnal' }}: kode, golongan, dan saldo normal tidak dapat diubah.
                </div>
            @endif

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <x-form.input name="code" label="Kode Akun" :value="$account->code" required :readonly="$locked" help="Mis. 6110. Urutan kode menentukan urutan di laporan." tip="Awalan kode menunjukkan golongan: 1 Aset, 2 Kewajiban, 3 Ekuitas, 4 Pendapatan, 5 HPP, 6 Beban, 7–8 Pendapatan/Beban lain. Akun sistem tidak bisa diganti kodenya." />
                <x-form.input name="name" label="Nama Akun" :value="$account->name" required />
                @if ($locked)
                    <input type="hidden" name="type" value="{{ $account->type->value }}">
                    <x-form.input name="type_label" label="Golongan" :value="$account->type->label()" disabled />
                @else
                    <x-form.select name="type" label="Golongan" :options="AccountType::options()" tip="Menentukan akun tampil di Neraca (Aset, Kewajiban, Ekuitas) atau Laba Rugi (Pendapatan, HPP, Beban)." :value="$account->type" required :placeholder="false" />
                @endif
                <x-form.select name="parent_id" label="Akun Induk (header)" tip="Akun pengelompok di atasnya. Saldo akun ini ikut dijumlahkan ke induknya di laporan." :options="$parents" :value="$account->parent_id" placeholder="Tanpa induk" data-select />
                @unless ($locked)
                    <x-form.select name="normal_balance" label="Saldo Normal" :options="['debit' => 'Debit', 'credit' => 'Kredit']" :value="$account->normal_balance" placeholder="Ikuti golongan" help="Ubah hanya untuk akun kontra, mis. akumulasi penyusutan (kredit)." tip="Sisi yang menambah saldo akun. Aset & beban normalnya Debit; kewajiban, ekuitas & pendapatan normalnya Kredit." />
                    <div class="flex items-end pb-2"><x-form.checkbox name="is_header" label="Akun induk / header (tidak dapat dijurnal)" tip="Akun header hanya untuk pengelompokan dan penjumlahan di laporan. Transaksi harus memakai akun di bawahnya." :checked="$account->is_header" /></div>
                @endunless
                <x-form.textarea name="description" label="Keterangan" :value="$account->description" rows="2" class="md:col-span-2" />

                <div x-data="{ cash: @js((bool) old('is_cash', $account->is_cash)) }" class="grid grid-cols-1 gap-4 rounded-lg border border-gray-200 p-4 md:col-span-2 md:grid-cols-2">
                    <div class="md:col-span-2">
                        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                            <input type="hidden" name="is_cash" value="0">
                            <input type="checkbox" name="is_cash" value="1" x-model="cash" class="rounded border-gray-300 text-indigo-600" @disabled($account->is_system && $account->is_cash)>
                            @if ($account->is_system && $account->is_cash)<input type="hidden" name="is_cash" value="1">@endif
                            Rekening kas / bank (golongan Aset)
                        </label>
                        <p class="mt-1 text-xs text-gray-500">Muncul di menu Kas &amp; Bank, bisa dipilih saat menerima/membayar, dan dihitung sebagai kas di laporan arus kas.</p>
                        @error('is_cash')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <template x-if="cash">
                        <div class="contents">
                            <x-form.input name="bank_name" label="Nama Bank" :value="$account->bank_name" placeholder="Mis. BCA KCP Bandung" />
                            <x-form.input name="bank_account_number" label="No. Rekening" :value="$account->bank_account_number" />
                            <x-form.input name="bank_account_holder" label="Atas Nama" :value="$account->bank_account_holder" />
                        </div>
                    </template>
                    <template x-if="!cash">
                        <x-form.select name="cash_flow_activity" label="Kelompok Arus Kas" :options="CashFlowActivity::options()" :value="$account->cash_flow_activity" :placeholder="'Otomatis ('.($account->exists ? $account->defaultCashFlowActivity()->label() : 'menurut kelompok akun').')'"
                                       help="Untuk laporan arus kas. Otomatis: aset tetap = investasi, hutang jangka panjang & modal = pendanaan, lainnya = operasi." tip="Menentukan di bagian mana uang yang melewati akun ini dilaporkan pada laporan arus kas: Operasi, Investasi atau Pendanaan." />
                    </template>
                </div>
                <x-form.checkbox name="is_active" label="Aktif" :checked="$account->is_active ?? true" tip="Akun nonaktif tidak bisa dipilih di jurnal atau transaksi baru, tetapi saldonya tetap tampil di laporan." />
            </div>

            <x-form.actions :cancel="route('accounts.index')" />
        </form>

        @if ($account->exists && $account->canDelete())
            <div class="mt-4 border-t border-gray-200 pt-4">
                <x-delete-button :action="route('accounts.destroy', $account)" />
            </div>
        @endif
    </x-card>
</x-layouts.app>
