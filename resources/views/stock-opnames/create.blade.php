<x-layouts.app title="Stock Opname Baru">
    <form method="POST" action="{{ route('stock-opnames.store') }}" class="space-y-6" x-data="{ scope: '{{ old('scope', 'materials') }}' }">
        @csrf
        <x-card title="Lembar Hitung">
            <p class="mb-4 text-sm text-gray-500">Sistem mencatat stok tercatat di gudang saat ini sebagai pembanding. Cetak lembar hitung (tanpa angka sistem), hitung fisik, lalu isi hasilnya. Selisih baru disesuaikan saat diposting.</p>
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <x-form.input name="date" label="Tanggal Opname" :value="today()" required data-date />
                <x-form.warehouse :value="request('warehouse_id')" />
                <div>
                    <label class="form-label">Jenis Barang <span class="text-red-500">*</span></label>
                    <select name="scope" class="form-control" x-model="scope" required>
                        @foreach (\App\Models\StockOpname::SCOPES as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('scope')<p class="mt-1 text-sm text-red-600">{{ $message }}</p>@enderror
                </div>
                @foreach ($categories as $scope => $options)
                    <div x-show="scope === '{{ $scope }}'">
                        <label class="form-label">Kategori</label>
                        <select name="category_id" class="form-control" :disabled="scope !== '{{ $scope }}'">
                            <option value="">Semua kategori</option>
                            @foreach ($options as $id => $name)
                                <option value="{{ $id }}" @selected(old('category_id') == $id)>{{ $name }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
                <x-form.input name="notes" label="Catatan" placeholder="mis. Opname akhir bulan" />
                <div class="flex items-end pb-2"><x-form.checkbox name="only_in_stock" label="Hanya barang yang tercatat ada stoknya" /></div>
            </div>
            @error('warehouse_id')<p class="mt-2 text-sm text-red-600">{{ $message }}</p>@enderror
        </x-card>

        <div class="flex justify-end gap-2">
            <a href="{{ route('stock-opnames.index') }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary">Buat Lembar Hitung</button>
        </div>
    </form>
</x-layouts.app>
