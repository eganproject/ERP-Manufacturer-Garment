@use('App\Enums\AccountType')
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
                <x-form.input name="code" label="Kode Akun" :value="$account->code" required :readonly="$locked" help="Mis. 6110. Urutan kode menentukan urutan di laporan." />
                <x-form.input name="name" label="Nama Akun" :value="$account->name" required />
                @if ($locked)
                    <input type="hidden" name="type" value="{{ $account->type->value }}">
                    <x-form.input name="type_label" label="Golongan" :value="$account->type->label()" disabled />
                @else
                    <x-form.select name="type" label="Golongan" :options="AccountType::options()" :value="$account->type" required :placeholder="false" />
                @endif
                <x-form.select name="parent_id" label="Akun Induk (header)" :options="$parents" :value="$account->parent_id" placeholder="Tanpa induk" data-select />
                @unless ($locked)
                    <x-form.select name="normal_balance" label="Saldo Normal" :options="['debit' => 'Debit', 'credit' => 'Kredit']" :value="$account->normal_balance" placeholder="Ikuti golongan" help="Ubah hanya untuk akun kontra, mis. akumulasi penyusutan (kredit)." />
                    <div class="flex items-end pb-2"><x-form.checkbox name="is_header" label="Akun induk / header (tidak dapat dijurnal)" :checked="$account->is_header" /></div>
                @endunless
                <x-form.textarea name="description" label="Keterangan" :value="$account->description" rows="2" class="md:col-span-2" />
                <x-form.checkbox name="is_active" label="Aktif" :checked="$account->is_active ?? true" />
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
