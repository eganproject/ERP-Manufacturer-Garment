<x-layouts.app :title="$store->exists ? 'Ubah Toko Marketplace' : 'Tambah Toko Marketplace'">
    <form method="POST" action="{{ $store->exists ? route('marketplace-stores.update', $store) : route('marketplace-stores.store') }}" class="space-y-6">
        @csrf
        @if ($store->exists) @method('PUT') @endif

        <x-card title="Toko" class="max-w-3xl">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-form.select name="platform" label="Platform" :options="$platforms" :value="$store->platform" :placeholder="false" required />
                <x-form.input name="name" label="Nama Toko" :value="$store->name" required class="sm:col-span-2" placeholder="mis. Garmen Official Store" />
                <x-form.input name="shop_id" label="ID Toko di Platform" :value="$store->shop_id" help="Shop ID Shopee / TikTok (wajib bila memakai API)." />
                <x-form.warehouse :value="$store->warehouse_id" label="Gudang Sumber Stok" help="Stok toko dialokasikan & dikirim dari gudang ini." />
                <x-form.input name="tax_rate" label="PPN dalam Harga (%)" :value="(float) $store->tax_rate" data-money help="Isi 11 bila PKP (harga marketplace termasuk PPN), 0 bila tidak." />
                <x-form.select name="balance_account_id" label="Rekening Saldo Penjual" :options="$accounts" :value="$store->balance_account_id" placeholder="Saldo Penjual Marketplace (default)" class="sm:col-span-2"
                               help="Dana pencairan masuk ke akun ini; penarikan ke bank dicatat sebagai transfer antar rekening di Kas & Bank." />
            </div>
            <x-form.textarea name="notes" label="Catatan" :value="$store->notes" rows="2" class="mt-4" />
            <div class="mt-4"><x-form.checkbox name="is_active" label="Toko aktif" :checked="$store->is_active" /></div>
        </x-card>

        <x-card title="Integrasi API (opsional)" class="max-w-3xl">
            <p class="mb-4 text-sm text-gray-500">
                Jalur utama adalah impor file ekspor pesanan dari Seller Center. Bila Anda sudah terdaftar sebagai partner (Shopee Open Platform / TikTok Shop Partner Center),
                isi kredensial di bawah agar pesanan ditarik otomatis tiap 30 menit. Rahasia disimpan terenkripsi; kosongkan untuk tetap memakai nilai lama.
            </p>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-form.input name="api_partner_id" label="Partner ID / App Key" :value="$store->api_partner_id" />
                <x-form.input name="api_partner_key" label="Partner Key / App Secret" type="password" autocomplete="new-password" :placeholder="$store->api_partner_key ? '•••••• (tersimpan)' : ''" />
                <x-form.input name="api_access_token" label="Access Token" type="password" autocomplete="new-password" :placeholder="$store->api_access_token ? '•••••• (tersimpan)' : ''" />
                <x-form.input name="api_refresh_token" label="Refresh Token" type="password" autocomplete="new-password" :placeholder="$store->api_refresh_token ? '•••••• (tersimpan)' : ''" />
                <x-form.input name="api_shop_cipher" label="Shop Cipher (khusus TikTok Shop)" :value="$store->api_shop_cipher" />
            </div>
            <div class="mt-4"><x-form.checkbox name="api_enabled" label="Aktifkan sinkron API" :checked="$store->api_enabled" /></div>
        </x-card>

        <div class="max-w-3xl">
            <x-form.actions :cancel="route('marketplace-stores.index')" />
        </div>
    </form>

    @if ($store->exists)
        <div class="mt-4 max-w-3xl"><x-delete-button :action="route('marketplace-stores.destroy', $store)" class="btn-secondary !text-red-600" label="Hapus Toko" /></div>
    @endif
</x-layouts.app>
