@use('App\Enums\DepreciationMethod')
<x-layouts.app :title="'Ubah Aset '.$asset->number">
    <x-card class="max-w-4xl">
        <form method="POST" action="{{ route('fixed-assets.update', $asset) }}" class="space-y-4">
            @csrf
            @method('PUT')
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <x-form.input name="name" label="Nama Aset" :value="$asset->name" required class="md:col-span-2" />
                <x-form.input name="serial_number" label="No. Seri / Plat" :value="$asset->serial_number" />
                <x-form.input name="location" label="Lokasi / Bagian" :value="$asset->location" />
                @if ($asset->canEditDepreciation())
                    <x-form.select name="depreciation_method" label="Metode Penyusutan" tip="Garis lurus: penyusutan sama setiap bulan (nilai disusutkan ÷ masa manfaat).
Saldo menurun: penyusutan besar di awal lalu mengecil (nilai buku awal tahun × 2 ÷ masa manfaat), cocok untuk mesin/kendaraan." :options="DepreciationMethod::options()" :value="$asset->depreciation_method" :placeholder="false" required />
                    <x-form.input name="useful_life_months" type="number" min="0" label="Masa Manfaat (bulan)" :value="$asset->useful_life_months" tip="Perkiraan lama aset dipakai, dalam bulan. Mis. mesin jahit 96 (8 tahun), kendaraan 96, inventaris kantor 48. Isi 0 untuk aset yang tidak disusutkan seperti tanah." required />
                    <x-form.input name="residual_value" label="Nilai Sisa (Rp)" tip="Perkiraan nilai aset di akhir masa manfaat. Penyusutan berhenti saat nilai buku mencapai angka ini." :value="(float) $asset->residual_value" data-money inputmode="numeric" />
                    <x-form.input name="depreciation_start" label="Mulai Disusutkan" tip="Bulan pertama penyusutan dibukukan." :value="$asset->depreciation_start" data-date />
                @else
                    <p class="text-sm text-gray-500 md:col-span-2">Metode & masa manfaat tidak bisa diubah karena penyusutan sudah diposting.</p>
                @endif
                <x-form.textarea name="notes" label="Catatan" :value="$asset->notes" rows="2" class="md:col-span-3" />
            </div>
            <x-form.actions :cancel="route('fixed-assets.show', $asset)" />
        </form>
    </x-card>
</x-layouts.app>
