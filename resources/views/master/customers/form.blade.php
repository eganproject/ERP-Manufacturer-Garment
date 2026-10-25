<x-layouts.app :title="$customer->exists ? 'Ubah Pelanggan' : 'Tambah Pelanggan'">
    <x-card class="max-w-3xl">
        <form method="POST" action="{{ $customer->exists ? route('customers.update', $customer) : route('customers.store') }}">
            @csrf
            @if ($customer->exists) @method('PUT') @endif
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-form.input name="code" label="Kode" :value="$customer->code" required />
                <x-form.input name="name" label="Nama" :value="$customer->name" required />
                <x-form.input name="contact_person" label="Nama Kontak" :value="$customer->contact_person" />
                <x-form.input name="phone" label="Telepon / WA" :value="$customer->phone" data-phone placeholder="08xx-xxxx-xxxx" />
                <x-form.input name="email" type="email" label="Email" :value="$customer->email" />
                <x-form.input name="tax_number" label="NPWP" :value="$customer->tax_number" />
                <x-form.input name="payment_terms" type="number" min="0" max="365" label="Termin Pembayaran (hari)" :value="$customer->payment_terms ?? 30" required help="Dipakai untuk menghitung jatuh tempo invoice/tagihan. 0 = tunai." />
                <x-form.textarea name="address" label="Alamat" :value="$customer->address" class="sm:col-span-2" />
                <x-form.checkbox name="is_active" label="Aktif" :checked="$customer->is_active" />
            </div>
            <x-form.actions :cancel="route('customers.index')" />
        </form>
    </x-card>
</x-layouts.app>
