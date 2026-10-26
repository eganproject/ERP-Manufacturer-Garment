<x-layouts.app title="Produk">
    <x-slot:actions><a href="{{ route('products.create') }}" class="btn-primary">+ Tambah Produk</a></x-slot:actions>

    <x-card :padding="false">
        <div class="border-b border-gray-200 p-4">
            <x-search-filter placeholder="Cari kode / nama...">
                <div class="w-52">
                    <x-form.select name="category_id" :options="$categories->pluck('name', 'id')" :value="request('category_id')" placeholder="Semua kategori" />
                </div>
            </x-search-filter>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>Kode</th><th>Nama</th><th>Kategori</th><th class="text-right">Harga Jual</th><th class="text-right">Varian</th><th class="text-right">Stok</th><th class="text-right">Aksi</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($products as $product)
                        <tr>
                            <td class="font-medium">{{ $product->code }}</td>
                            <td><a href="{{ route('products.show', $product) }}" class="text-indigo-600 hover:underline">{{ $product->name }}</a></td>
                            <td>{{ $product->category?->name ?? '-' }}</td>
                            <td class="text-right">{{ rupiah($product->price) }}</td>
                            <td class="text-right">{{ $product->variants_count }}</td>
                            <td class="text-right">{{ qty($product->variants_sum_stock) }} {{ $product->unit->code }}</td>
                            <td class="space-x-3 text-right whitespace-nowrap">
                                <a href="{{ route('products.edit', $product) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Ubah</a>
                                <x-delete-button :action="route('products.destroy', $product)" />
                            </td>
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
