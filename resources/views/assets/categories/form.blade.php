@use('App\Enums\DepreciationMethod')
<x-layouts.app :title="$category->exists ? 'Ubah Kategori Aset' : 'Tambah Kategori Aset'">
    <x-card class="max-w-3xl">
        <form method="POST" action="{{ $category->exists ? route('asset-categories.update', $category) : route('asset-categories.store') }}" class="space-y-4">
            @csrf
            @if ($category->exists) @method('PUT') @endif
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <x-form.input name="code" label="Kode" :value="$category->code" required placeholder="mis. MSN" />
                <x-form.input name="name" label="Nama Kategori" :value="$category->name" required class="md:col-span-2" placeholder="mis. Mesin Jahit" />
                <x-form.select name="depreciation_method" label="Metode Penyusutan" tip="Garis lurus: penyusutan sama setiap bulan (nilai disusutkan ÷ masa manfaat).
Saldo menurun: penyusutan besar di awal lalu mengecil (nilai buku awal tahun × 2 ÷ masa manfaat), cocok untuk mesin/kendaraan." :options="DepreciationMethod::options()" :value="$category->depreciation_method" :placeholder="false" required />
                <x-form.input name="useful_life_months" type="number" min="0" label="Masa Manfaat (bulan)" :value="$category->useful_life_months" required help="0 = tidak disusutkan (mis. tanah). 48 = 4 tahun, 96 = 8 tahun." tip="Perkiraan lama aset dipakai, dalam bulan. Mis. mesin jahit 96 (8 tahun), kendaraan 96, inventaris kantor 48. Isi 0 untuk aset yang tidak disusutkan seperti tanah." />
                <x-form.input name="residual_rate" type="number" step="any" min="0" max="99" label="Nilai Sisa (% harga perolehan)" tip="Perkiraan nilai jual aset di akhir masa manfaat. Bagian ini tidak ikut disusutkan. Mis. 10 berarti mesin Rp 10 juta disusutkan sampai tersisa Rp 1 juta." :value="$category->residual_rate ?? 0" />
                <x-form.select name="asset_account_id" label="Akun Aset" tip="Akun neraca tempat harga perolehan aset dicatat, mis. Mesin & Peralatan." :options="$assetAccounts" :value="$category->asset_account_id" required data-select />
                <x-form.select name="accumulated_account_id" label="Akun Akumulasi Penyusutan" tip="Akun pengurang aset yang menampung total penyusutan yang sudah dibukukan." :options="$assetAccounts" :value="$category->accumulated_account_id" data-select />
                <x-form.select name="expense_account_id" label="Akun Beban Penyusutan" tip="Akun biaya di laba rugi untuk penyusutan bulanan. Aset produksi sebaiknya ke beban overhead pabrik." :options="$expenseAccounts" :value="$category->expense_account_id" data-select
                               help="Aset pabrik → Penyusutan Mesin & Bangunan Pabrik (overhead), aset kantor → Penyusutan Aset Kantor." />
                <x-form.textarea name="notes" label="Catatan" :value="$category->notes" rows="2" class="md:col-span-3" />
                <x-form.checkbox name="is_active" label="Aktif (bisa dipilih saat mencatat aset)" tip="Kategori nonaktif tidak muncul saat mencatat aset baru. Aset yang sudah ada tidak terpengaruh." :checked="$category->is_active" class="md:col-span-3" />
            </div>
            <x-form.actions :cancel="route('asset-categories.index')" />
        </form>
    </x-card>
</x-layouts.app>
