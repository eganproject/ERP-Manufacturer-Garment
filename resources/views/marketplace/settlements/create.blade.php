<x-layouts.app title="Impor Pencairan Dana">
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Unggah Laporan Penghasilan / Settlement" class="lg:col-span-2">
            <form method="POST" action="{{ route('marketplace-settlements.store') }}" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <x-form.select name="marketplace_store_id" label="Toko" :options="$stores" required />
                <div>
                    <label class="form-label">File (.xlsx / .csv) <span class="text-red-500">*</span></label>
                    <input type="file" name="file" accept=".xlsx,.csv,.txt" required class="block w-full text-sm">
                    @error('file')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <x-form.input name="date" label="Tanggal Pencairan" data-date help="Kosongkan untuk memakai tanggal dana dilepaskan terakhir di file." />
                <x-form.textarea name="notes" label="Catatan" rows="2" />
                <x-form.actions :cancel="route('marketplace-settlements.index')" submit="Impor sebagai Draft" />
            </form>
        </x-card>

        <x-card title="Cara mengambil file">
            <div class="space-y-3 text-sm text-gray-600">
                <p><strong>Shopee:</strong> Seller Centre → Keuangan → Penghasilan Saya → Sudah Dilepas → Ekspor / Unduh laporan penghasilan.</p>
                <p><strong>TikTok Shop:</strong> Seller Center → Keuangan → Laporan / Settlement → unduh rincian pesanan (order details).</p>
                <p>Kolom yang dibaca: nomor pesanan, nilai pesanan, voucher penjual, komisi/biaya admin, biaya layanan, biaya transaksi, ongkir yang ditanggung penjual, biaya lain (afiliasi), penyesuaian, dan total penghasilan.
                    Bila komponen tidak menjumlah ke dana yang cair, sisanya dicatat sebagai penyesuaian sehingga dana bersih selalu sesuai file.</p>
                <p>Hasil impor berupa <em>draft</em>: periksa dulu, baru posting. <a href="{{ route('marketplace-settlements.template') }}" class="text-indigo-600 hover:underline">Template CSV</a> tersedia untuk input manual.</p>
            </div>
        </x-card>
    </div>
</x-layouts.app>
