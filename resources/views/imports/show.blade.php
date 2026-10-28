<x-layouts.app :title="'Impor '.$importer->label()" :subtitle="$importer->description()">
    <x-slot:actions>
        <a href="{{ route('imports.index') }}" class="btn-secondary">Semua Jenis Impor</a>
        @if ($importer->indexRoute() && Route::has($importer->indexRoute()))
            <a href="{{ route($importer->indexRoute()) }}" class="btn-secondary">Lihat Data</a>
        @endif
    </x-slot:actions>

    <x-guide :title="'Langkah impor '.mb_strtolower($importer->label())" :steps="array_values(array_filter([
        '**Unduh Template Excel**, isi sheet **Data** mulai baris ke-2 (kolom bertanda * wajib).',
        $importer->exportRows() !== null ? 'Untuk ubah massal data yang sudah ada: **Unduh Data Saat Ini**, ubah di Excel, lalu unggah lagi. Kode yang sama = diperbarui.' : null,
        'Unggah file .xlsx / .xls / .csv. Semua baris diperiksa dulu; satu baris salah = tidak ada yang disimpan.',
    ]))"></x-guide>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-card title="Unggah File" subtitle="Excel (.xlsx / .xls) atau CSV, maksimal 20 MB">
                <form method="POST" action="{{ route('imports.store', $importer->key()) }}" enctype="multipart/form-data" class="space-y-4">
                    @csrf
                    <div>
                        <x-form.label label="File data" :required="true" tip="Sheet pertama yang dibaca. Baris judul kolom harus sama dengan template (boleh ada baris judul laporan di atasnya)." />
                        <input type="file" name="file" accept=".xlsx,.xls,.csv,.txt" required class="block w-full text-sm">
                    </div>
                    @if ($errors->has('file'))
                        <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                            <ul class="space-y-1">
                                @foreach ($errors->get('file') as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                    <x-form.actions :cancel="route('imports.index')" submit="Impor" />
                </form>
            </x-card>

            <x-card title="Kolom Template" :padding="false">
                <table class="table-base">
                    <thead><tr><th>Kolom</th><th>Wajib</th><th>Keterangan</th><th>Contoh</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($importer->columns() as [$header, $opt])
                            <tr>
                                <td class="font-medium whitespace-nowrap">{{ $header }}</td>
                                <td>@if ($opt['required'] ?? false)<x-badge color="amber">Wajib</x-badge>@else<span class="text-xs text-gray-500">Opsional</span>@endif</td>
                                <td class="text-sm text-gray-600">{{ $importer->describe($opt) }}</td>
                                <td class="text-sm whitespace-nowrap text-gray-600">{{ is_bool($opt['example'] ?? null) ? (($opt['example'] ?? false) ? 'Ya' : 'Tidak') : ($opt['example'] ?? '') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </x-card>
        </div>

        <div class="space-y-6">
            <x-card title="Template">
                <div class="space-y-3">
                    <a href="{{ route('imports.template', $importer->key()) }}" class="btn-primary w-full justify-center">Unduh Template Excel</a>
                    @if ($importer->exportRows() !== null)
                        <a href="{{ route('imports.template', [$importer->key(), 'data' => 1]) }}" class="btn-secondary w-full justify-center">Unduh Data Saat Ini</a>
                    @endif
                    <p class="text-xs text-gray-500">Template berisi sheet <strong>Data</strong> (diisi), <strong>Contoh</strong> dan <strong>Petunjuk</strong>.</p>
                </div>
            </x-card>

            <x-card title="Perlu diketahui">
                <ul class="list-disc space-y-2 pl-4 text-sm text-gray-600">
                    <li>Kode / SKU yang sudah ada akan diperbarui; sel kosong tidak mengubah nilai lama.</li>
                    <li>Satu baris salah = tidak ada data yang disimpan, jadi aman diulang setelah diperbaiki.</li>
                    @foreach ($importer->notes() as $note)
                        <li>{{ $note }}</li>
                    @endforeach
                </ul>
            </x-card>
        </div>
    </div>
</x-layouts.app>
