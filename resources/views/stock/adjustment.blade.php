<x-layouts.app title="Penyesuaian Stok">
    <x-guide title="Kapan memakai penyesuaian stok?" :steps="['Untuk koreksi cepat satu barang: barang hilang, rusak, salah input, atau **saldo awal** saat pertama memakai sistem.', 'Untuk hitung fisik banyak barang sekaligus, lebih baik pakai menu **Stock Opname**.', 'Selisih nilai otomatis dijurnal ke akun Selisih Persediaan (penyesuaian pertama dianggap saldo awal). Untuk saldo awal isi **Harga pokok / unit** agar nilai persediaan benar.', 'Saldo awal banyak barang sekaligus: pakai **Impor Stok Awal** dari Excel.']"></x-guide>
    <x-card class="max-w-2xl" title="Koreksi stok hasil stock opname, barang rusak, dll.">
        <form method="POST" action="{{ route('stock.adjustment.store') }}" class="space-y-4"
              data-confirm="Stok akan diubah dan tercatat di kartu stok." data-confirm-icon="question" data-confirm-button="Ya, simpan">
            @csrf
            <x-form.select name="item" label="Barang" required tip="Bahan baku atau varian barang jadi yang stoknya dikoreksi. Ketik kode/SKU untuk mencari." data-select data-placeholder="Cari bahan / SKU...">
                <optgroup label="Bahan Baku">
                    @foreach ($materials as $m)
                        <option value="material:{{ $m->id }}" @selected(old('item') === 'material:'.$m->id)>{{ $m->display_name }} — stok {{ qty($m->stock) }} {{ $m->unit->code }}</option>
                    @endforeach
                </optgroup>
                <optgroup label="Barang Jadi">
                    @foreach ($variants as $v)
                        <option value="variant:{{ $v->id }}" @selected(old('item') === 'variant:'.$v->id)>{{ $v->sku }} · {{ $v->display_name }} — stok {{ qty($v->stock) }}</option>
                    @endforeach
                </optgroup>
            </x-form.select>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-form.input name="date" label="Tanggal" :value="now()" required data-date />
                <x-form.warehouse />
                <x-form.select name="mode" label="Jenis" tip="Tambah: stok naik sebesar jumlah. Kurangi: stok turun sebesar jumlah. Set stok fisik: stok diganti menjadi angka hasil hitung (sistem menghitung selisihnya)." :options="['in' => 'Tambah (+)', 'out' => 'Kurangi (−)', 'set' => 'Set stok fisik (opname)']" value="set" required :placeholder="false" />
                <x-form.input name="quantity" type="number" step="0.01" min="0" label="Jumlah" required tip="Untuk Tambah/Kurangi: besar perubahan. Untuk Set stok fisik: jumlah hasil hitung di gudang." />
                <x-form.input name="unit_cost" label="Harga pokok / unit" data-money tip="Hanya dipakai bila stok bertambah (mis. saldo awal). Nilai persediaan = jumlah × harga ini, dan harga pokok rata-rata barang ikut diperbarui.\nKosong = harga pokok rata-rata saat ini (bahan: harga beli)." />
            </div>
            <x-form.input name="notes" label="Alasan" required tip="Wajib diisi agar koreksi stok bisa dipertanggungjawabkan. Tampil di kartu stok dan riwayat aktivitas." placeholder="mis. Hasil stock opname Oktober" />
            <x-form.actions :cancel="route('stock.materials')" />
        </form>
    </x-card>
</x-layouts.app>
