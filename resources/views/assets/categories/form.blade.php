@use('App\Enums\DepreciationMethod')
<x-layouts.app :title="$category->exists ? 'Ubah Kategori Aset' : 'Tambah Kategori Aset'">
    <x-card class="max-w-3xl">
        <form method="POST" action="{{ $category->exists ? route('asset-categories.update', $category) : route('asset-categories.store') }}" class="space-y-4">
            @csrf
            @if ($category->exists) @method('PUT') @endif
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <x-form.input name="code" label="Kode" :value="$category->code" required placeholder="mis. MSN" />
                <x-form.input name="name" label="Nama Kategori" :value="$category->name" required class="md:col-span-2" placeholder="mis. Mesin Jahit" />
                <x-form.select name="depreciation_method" label="Metode Penyusutan" :options="DepreciationMethod::options()" :value="$category->depreciation_method" :placeholder="false" required />
                <x-form.input name="useful_life_months" type="number" min="0" label="Masa Manfaat (bulan)" :value="$category->useful_life_months" required help="0 = tidak disusutkan (mis. tanah). 48 = 4 tahun, 96 = 8 tahun." />
                <x-form.input name="residual_rate" type="number" step="any" min="0" max="99" label="Nilai Sisa (% harga perolehan)" :value="$category->residual_rate ?? 0" />
                <x-form.select name="asset_account_id" label="Akun Aset" :options="$assetAccounts" :value="$category->asset_account_id" required data-select />
                <x-form.select name="accumulated_account_id" label="Akun Akumulasi Penyusutan" :options="$assetAccounts" :value="$category->accumulated_account_id" data-select />
                <x-form.select name="expense_account_id" label="Akun Beban Penyusutan" :options="$expenseAccounts" :value="$category->expense_account_id" data-select
                               help="Aset pabrik → Penyusutan Mesin & Bangunan Pabrik (overhead), aset kantor → Penyusutan Aset Kantor." />
                <x-form.textarea name="notes" label="Catatan" :value="$category->notes" rows="2" class="md:col-span-3" />
                <x-form.checkbox name="is_active" label="Aktif (bisa dipilih saat mencatat aset)" :checked="$category->is_active" class="md:col-span-3" />
            </div>
            <x-form.actions :cancel="route('asset-categories.index')" />
        </form>
    </x-card>
</x-layouts.app>
