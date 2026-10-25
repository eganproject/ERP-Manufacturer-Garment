<x-layouts.app :title="$supplier->exists ? 'Ubah Supplier' : 'Tambah Supplier'">
    <x-card class="max-w-3xl">
        <form method="POST" action="{{ $supplier->exists ? route('suppliers.update', $supplier) : route('suppliers.store') }}">
            @csrf
            @if ($supplier->exists) @method('PUT') @endif
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-form.input name="code" label="Kode" :value="$supplier->code" required />
                <x-form.input name="name" label="Nama" :value="$supplier->name" required />
                <x-form.input name="contact_person" label="Nama Kontak" :value="$supplier->contact_person" />
                <x-form.input name="phone" label="Telepon / WA" :value="$supplier->phone" data-phone placeholder="08xx-xxxx-xxxx" />
                <x-form.input name="email" type="email" label="Email" :value="$supplier->email" />
                <x-form.input name="tax_number" label="NPWP" :value="$supplier->tax_number" />
                <x-form.input name="payment_terms" type="number" min="0" max="365" label="Termin Pembayaran (hari)" :value="$supplier->payment_terms ?? 30" required help="Dipakai untuk menghitung jatuh tempo invoice/tagihan. 0 = tunai." />
                <x-form.textarea name="address" label="Alamat" :value="$supplier->address" class="sm:col-span-2" />
                <x-form.checkbox name="is_active" label="Aktif" :checked="$supplier->is_active" />
            </div>
            <x-form.actions :cancel="route('suppliers.index')" />
        </form>
    </x-card>
</x-layouts.app>
