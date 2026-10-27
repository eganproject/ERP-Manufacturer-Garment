<x-layouts.app :title="$store->exists ? 'Ubah Toko Marketplace' : 'Tambah Toko Marketplace'">
    <x-guide title="Menyiapkan toko marketplace" :steps="['Pilih platform (TikTok Shop / Shopee), nama toko dan **gudang sumber stok** (disarankan gudang khusus online).', 'Isi PPN dalam harga bila perusahaan PKP, dan rekening saldo penjual tempat dana pencairan masuk.', 'Lanjutkan ke **Mapping SKU** bila SKU di Seller Center berbeda dengan SKU varian di ERP, lalu impor pesanan.']"></x-guide>
    <form method="POST" action="{{ $store->exists ? route('marketplace-stores.update', $store) : route('marketplace-stores.store') }}" class="space-y-6">
        @csrf
        @if ($store->exists) @method('PUT') @endif

        <x-card title="Toko" class="max-w-3xl">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-form.select name="platform" label="Platform" tip="Menentukan format file impor & alias kolom yang dipakai saat membaca file pesanan dan pencairan." :options="$platforms" :value="$store->platform" :placeholder="false" required />
                <x-form.input name="name" label="Nama Toko" :value="$store->name" required class="sm:col-span-2" placeholder="mis. Garmen Official Store" />
                <x-form.input name="shop_id" label="ID Toko di Platform" :value="$store->shop_id" help="Shop ID Shopee / TikTok (wajib bila memakai API)." tip="Nomor toko di Seller Center. Hanya diperlukan untuk sinkron API otomatis; boleh kosong bila hanya impor file." />
                <x-form.warehouse :value="$store->warehouse_id" label="Gudang Sumber Stok" help="Stok toko dialokasikan & dikirim dari gudang ini." tip="Pesanan marketplace mengurangi stok gudang ini saat dikirim, dan stok tersedia untuk toko dihitung dari gudang ini." />
                <x-form.input name="tax_rate" label="PPN dalam Harga (%)" :value="(float) $store->tax_rate" data-money help="Isi 11 bila PKP (harga marketplace termasuk PPN), 0 bila tidak." tip="Harga di marketplace dianggap sudah termasuk PPN. Sistem memisahkan PPN dari penjualan sesuai tarif ini dan memasukkannya ke Laporan PPN." />
                <x-form.select name="balance_account_id" label="Rekening Saldo Penjual" :options="$accounts" :value="$store->balance_account_id" placeholder="Saldo Penjual Marketplace (default)" class="sm:col-span-2"
                               help="Dana pencairan masuk ke akun ini; penarikan ke bank dicatat sebagai transfer antar rekening di Kas & Bank." tip="Saldo di aplikasi penjual diperlakukan seperti rekening sendiri. Pisahkan per toko bila ingin memantau saldo masing-masing." />
            </div>
            <x-form.textarea name="notes" label="Catatan" :value="$store->notes" rows="2" class="mt-4" />
            <div class="mt-4"><x-form.checkbox name="is_active" label="Toko aktif" tip="Toko nonaktif tidak muncul di pilihan impor dan tidak ikut sinkron API." :checked="$store->is_active" /></div>
        </x-card>

        <x-card title="Integrasi API (opsional)" class="max-w-3xl" tip="Hanya untuk penjual yang terdaftar sebagai partner resmi Shopee/TikTok. Tanpa API, cukup impor file ekspor dari Seller Center.">
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
            <div class="mt-4"><x-form.checkbox name="api_enabled" label="Aktifkan sinkron API" tip="Bila aktif, pesanan ditarik otomatis setiap 30 menit memakai kredensial di atas." :checked="$store->api_enabled" /></div>
        </x-card>

        <div class="max-w-3xl">
            <x-form.actions :cancel="route('marketplace-stores.index')" />
        </div>
    </form>

    @if ($store->exists)
        <div class="mt-4 max-w-3xl"><x-delete-button :action="route('marketplace-stores.destroy', $store)" class="btn-outline-danger" label="Hapus Toko" /></div>
    @endif
</x-layouts.app>
