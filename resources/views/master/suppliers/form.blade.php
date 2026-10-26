<x-layouts.app :title="$supplier->exists ? 'Ubah Supplier' : 'Tambah Supplier'">
    <x-card class="max-w-3xl">
        <form method="POST" action="{{ $supplier->exists ? route('suppliers.update', $supplier) : route('suppliers.store') }}">
            @csrf
            @if ($supplier->exists) @method('PUT') @endif
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-form.input name="code" label="Kode" :value="$supplier->code" required tip="Kode singkat dan unik untuk mencari supplier dengan cepat, mis. SUP-001." />
                <x-form.input name="name" label="Nama" :value="$supplier->name" required />
                <x-form.input name="contact_person" label="Nama Kontak" :value="$supplier->contact_person" />
                <x-form.input name="phone" label="Telepon / WA" :value="$supplier->phone" data-phone placeholder="08xx-xxxx-xxxx" />
                <x-form.input name="email" type="email" label="Email" :value="$supplier->email" />
                <x-form.input name="tax_number" label="NPWP" tip="Nomor pokok wajib pajak. Dicetak di dokumen dan dipakai untuk faktur pajak PPN. Kosongkan bila tidak ada." :value="$supplier->tax_number" />
                <x-form.input name="payment_terms" type="number" min="0" max="365" label="Termin Pembayaran (hari)" :value="$supplier->payment_terms ?? 30" required help="Dipakai untuk menghitung jatuh tempo invoice/tagihan. 0 = tunai." tip="Lama waktu kita boleh membayar supplier setelah tagihan dicatat. Mis. 30 berarti jatuh tempo 30 hari setelah tanggal tagihan dan muncul di laporan umur hutang." />
                <x-form.textarea name="address" label="Alamat" :value="$supplier->address" class="sm:col-span-2" />
                <div class="flex flex-wrap gap-6 sm:col-span-2">
                    <x-form.checkbox name="is_active" label="Aktif" :checked="$supplier->is_active" tip="Bila dinonaktifkan, supplier tidak bisa dipilih di transaksi baru. Transaksi lama tetap tersimpan." />
                    <x-form.checkbox name="is_subcontractor" label="Vendor maklon (CMT / jasa potong, jahit, finishing) — menerima titipan bahan" :checked="$supplier->is_subcontractor" tip="Centang bila supplier ini menerima pekerjaan maklon (jasa jahit/potong/finishing). Vendor maklon bisa dipilih di Order Maklon: kita kirim bahan ke mereka, lalu mereka mengembalikan hasil jadi dan menagih ongkos jasa." />
                </div>
            </div>
            <x-form.actions :cancel="route('suppliers.index')" />
        </form>
    </x-card>
</x-layouts.app>
