<x-layouts.app title="Stock Opname Baru">
    <x-guide title="Alur stock opname (hitung fisik)" :steps="['Pilih gudang dan jenis barang (bisa per kategori), lalu buat lembar hitung.', '**Cetak Lembar Hitung** (tanpa angka sistem) dan hitung fisik barang di gudang.', 'Isi jumlah fisik di sistem (boleh bertahap dengan **Simpan Hasil Hitung**).', '**Posting Opname**: selisih dengan stok sistem otomatis disesuaikan dan dijurnal. Barang yang tidak diisi tidak diubah.']"></x-guide>
    <form method="POST" action="{{ route('stock-opnames.store') }}" class="space-y-6" x-data="{ scope: '{{ old('scope', 'materials') }}' }">
        @csrf
        <x-card title="Lembar Hitung">
            <p class="mb-4 text-sm text-gray-500">Sistem mencatat stok tercatat di gudang saat ini sebagai pembanding. Cetak lembar hitung (tanpa angka sistem), hitung fisik, lalu isi hasilnya. Selisih baru disesuaikan saat diposting.</p>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <x-form.input name="date" label="Tanggal Opname" tip="Tanggal penghitungan fisik. Penyesuaian stok dicatat pada tanggal ini saat diposting." :value="today()" required data-date />
                <x-form.warehouse :value="request('warehouse_id')" tip="Satu opname untuk satu gudang. Yang dibandingkan adalah stok di gudang ini." />
                <div>
                    <x-form.label label="Jenis Barang" :required="true" tip="Bahan baku atau barang jadi. Pisahkan opname per jenis agar lembar hitung tidak terlalu panjang." />
                    <select name="scope" class="form-control" x-model="scope" required>
                        @foreach (\App\Models\StockOpname::SCOPES as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('scope')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                @foreach ($categories as $scope => $options)
                    <div x-show="scope === '{{ $scope }}'">
                        <x-form.label label="Kategori" tip="Batasi lembar hitung ke satu kategori (mis. hanya Kain). Pilih Semua kategori untuk menghitung semuanya." />
                        <select name="category_id" class="form-control" :disabled="scope !== '{{ $scope }}'">
                            <option value="">Semua kategori</option>
                            @foreach ($options as $id => $name)
                                <option value="{{ $id }}" @selected(old('category_id') == $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
                <x-form.input name="notes" label="Catatan" placeholder="mis. Opname akhir bulan" />
                <div class="flex items-end pb-2"><x-form.checkbox name="only_in_stock" label="Hanya barang yang tercatat ada stoknya" tip="Bila dicentang, barang yang stok sistemnya 0 tidak dimasukkan ke lembar hitung. Jangan centang bila ingin memastikan barang bernilai 0 memang kosong." /></div>
            </div>
            @error('warehouse_id')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
        </x-card>

        <div class="flex justify-end gap-2">
            <a href="{{ route('stock-opnames.index') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary">Buat Lembar Hitung</button>
        </div>
    </form>
</x-layouts.app>
