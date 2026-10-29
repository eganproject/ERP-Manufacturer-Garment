<x-layouts.app title="Tarif Borongan per Artikel">
    <x-card :padding="false">
        <div class="border-b border-gray-100 p-4">
            <x-search-filter placeholder="Cari kode / nama produk..." />
            <p class="mt-2 text-xs text-gray-500">Atur daftar operasi / segmen jahit beserta tarif per pcs, dan tarif per pcs jadi untuk karyawan yang mengerjakan satu baju dari awal sampai selesai. Artikel yang sudah punya tarif tidak lagi dibebani upah standar BOM saat SPK dimulai; upah aktual masuk dari hasil kerja harian.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>Kode</th><th>Produk</th><th class="text-right">Operasi Aktif</th><th class="text-right">Total Tarif Operasi / pcs</th><th class="text-right">Tarif pcs Jadi</th><th class="text-right">Upah Standar BOM</th><th class="text-right">Aksi</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($products as $product)
                        <tr>
                            <td class="font-medium">{{ $product->code }}</td>
                            <td>{{ $product->name }}</td>
                            <td class="text-right">{{ $product->operations_count }}</td>
                            <td class="text-right whitespace-nowrap">{{ $product->operations_count ? rupiah($product->operations_rate) : '-' }}</td>
                            <td class="text-right whitespace-nowrap">{{ (float) $product->full_garment_rate > 0 ? rupiah($product->full_garment_rate) : '-' }}</td>
                            <td class="text-right whitespace-nowrap text-gray-500">{{ $product->activeBom ? rupiah($product->activeBom->labor_cost) : '-' }}</td>
                            <td class="text-right"><a href="{{ route('piece-rates.edit', $product) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Atur Tarif</a></td>
                        </tr>
                    @empty
                        <x-empty-row colspan="7" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-4 py-3 empty:hidden">{{ $products->links() }}</div>
    </x-card>
</x-layouts.app>
