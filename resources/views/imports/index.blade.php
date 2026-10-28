<x-layouts.app title="Impor Data" subtitle="Masukkan data massal dari Excel (.xlsx / .xls) atau CSV">
    <x-guide title="Cara impor data" :steps="['Pilih jenis data di bawah, lalu **Unduh Template Excel**.', 'Isi sheet **Data** di Excel (contoh pengisian ada di sheet **Contoh**, penjelasan kolom di sheet **Petunjuk**).', 'Unggah file. Bila ada baris yang salah, tidak ada yang disimpan dan sistem menunjukkan nomor baris & penyebabnya.', 'Urutan yang disarankan untuk data awal: **Supplier → Bahan Baku → Produk & Varian → Pelanggan → Karyawan → Stok Awal → Jurnal Saldo Awal**.']"></x-guide>

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($importers as $importer)
            <a href="{{ route('imports.show', $importer->key()) }}" class="card flex items-start gap-3 p-5">
                <span class="icon-chip shrink-0">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" /></svg>
                </span>
                <span class="min-w-0">
                    <span class="block text-sm font-semibold text-gray-900">{{ $importer->label() }}</span>
                    <span class="mt-0.5 block text-xs text-gray-500">{{ $importer->description() }}</span>
                </span>
            </a>
        @endforeach
    </div>
</x-layouts.app>
