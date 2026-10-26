<x-layouts.app title="Pemetaan Akun Jurnal Otomatis">
    <form method="POST" action="{{ route('accounts.mapping.update') }}" class="space-y-6">
        @csrf
        @method('PUT')

        <div class="rounded-lg bg-indigo-50 px-4 py-3 text-sm text-indigo-800">
            Akun di bawah ini dipakai saat sistem membuat jurnal otomatis dari transaksi (penerimaan barang, surat jalan, invoice, tagihan, pembayaran, retur, produksi, maklon, dan stock opname).
            Perubahan hanya berlaku untuk transaksi berikutnya; jurnal yang sudah ada tidak berubah.
        </div>

        @foreach ($roles as $group => $items)
            <x-card :title="$group">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                    @foreach ($items as $role => [$label])
                        <x-form.select :name="'roles['.$role.']'" :label="$label" :options="$options" :value="$current[$role]" :tip="\App\Support\LedgerAccounts::HINTS[$role] ?? null" required :placeholder="false" data-select />
                    @endforeach
                </div>
            </x-card>
        @endforeach

        <div class="flex justify-end gap-2">
            <a href="{{ route('accounts.index') }}" class="btn-secondary">Kembali</a>
            <button type="submit" class="btn-primary">Simpan Pemetaan</button>
        </div>
    </form>
</x-layouts.app>
