<x-layouts.app title="Impor Pesanan Marketplace">
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Unggah File Pesanan" class="lg:col-span-2">
            @if (empty($stores))
                <p class="text-sm text-gray-600">Belum ada toko aktif. <a href="{{ route('marketplace-stores.create') }}" class="text-indigo-600 hover:underline">Tambah toko</a> terlebih dahulu.</p>
            @else
                <form method="POST" action="{{ route('marketplace-orders.import.store') }}" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                    <x-form.select name="marketplace_store_id" label="Toko" :options="$stores" :value="request('store_id')" required />
                    <div>
                        <label class="form-label">File ekspor pesanan (.xlsx / .csv) <span class="text-red-500">*</span></label>
                        <input type="file" name="file" accept=".xlsx,.csv,.txt" required class="block w-full text-sm">
                        @error('file')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <x-form.actions :cancel="route('marketplace-orders.index')" submit="Impor" />
                </form>
            @endif
        </x-card>

        <x-card title="Cara mengambil file">
            <div class="space-y-3 text-sm text-gray-600">
                <p><strong>Shopee:</strong> Seller Centre → Pesanan Saya → Ekspor (pilih rentang tanggal) → unduh file .xlsx.</p>
                <p><strong>TikTok Shop:</strong> Seller Center → Pesanan → Kelola pesanan → Ekspor → unduh .xlsx / .csv.</p>
                <p>Impor ulang file yang sama atau yang lebih baru aman: pesanan tidak digandakan, hanya status yang diperbarui. Pesanan berstatus dikirim/selesai otomatis mengurangi stok gudang toko; batal setelah dikirim / pengembalian masuk <em>Retur Diproses</em>.</p>
                <p>Tidak memakai file Seller Center? Gunakan <a href="{{ route('marketplace-orders.template') }}" class="text-indigo-600 hover:underline">template CSV</a>.</p>
            </div>
        </x-card>
    </div>
</x-layouts.app>
