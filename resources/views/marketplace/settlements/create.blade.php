<x-layouts.app title="Impor Pencairan Dana">
    <x-guide title="Alur pencairan dana marketplace" :steps="['Unduh laporan penghasilan/settlement dari Seller Center, lalu unggah di sini. Hasilnya berupa **draft** per pesanan.', 'Periksa peringatan: pesanan belum dikirim, tidak ditemukan, atau sudah pernah cair.', '**Posting** draft: piutang marketplace ditutup, biaya admin/ongkir/voucher dicatat, dan dana bersih masuk ke rekening saldo penjual.', 'Penarikan saldo ke bank dicatat lewat **Kas & Bank → Transfer**.']"></x-guide>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Unggah Laporan Penghasilan / Settlement" class="lg:col-span-2">
            <form method="POST" action="{{ route('marketplace-settlements.store') }}" enctype="multipart/form-data" class="space-y-4">
                @csrf
                <x-form.select name="marketplace_store_id" label="Toko" :options="$stores" required />
                <div>
                    <x-form.label label="File (.xlsx / .xls / .csv)" :required="true" tip="Shopee: Penghasilan Saya. TikTok Shop: rincian settlement. Unggah file aslinya tanpa diubah." />
                    <input type="file" name="file" accept=".xlsx,.xls,.csv,.txt" required class="block w-full text-sm">
                    @error('file')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <x-form.input name="date" label="Tanggal Pencairan" data-date help="Kosongkan untuk memakai tanggal dana dilepaskan terakhir di file." tip="Tanggal dana masuk ke saldo penjual. Dipakai sebagai tanggal jurnal pencairan." />
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
                <p>Hasil impor berupa <em>draft</em>: periksa dulu, baru posting. <a href="{{ route('marketplace-settlements.template') }}" class="text-indigo-600 hover:underline">Template Excel</a> tersedia untuk input manual.</p>
            </div>
        </x-card>
    </div>
</x-layouts.app>
