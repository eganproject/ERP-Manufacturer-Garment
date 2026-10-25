<x-layouts.app :title="$product->name">
    <x-slot:actions>
        @can('production.manage')
            <a href="{{ route('production-orders.create', ['product_id' => $product->id]) }}" class="btn-secondary">Buat SPK</a>
        @endcan
        @can('boms.manage')
            <a href="{{ route('boms.create', ['product_id' => $product->id]) }}" class="btn-secondary">+ BOM</a>
        @endcan
        <a href="{{ route('products.edit', $product) }}" class="btn-primary">Ubah Produk</a>
    </x-slot:actions>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Informasi">
            <dl class="space-y-3 text-sm">
                <div><dt class="text-gray-500">Kode</dt><dd class="font-medium">{{ $product->code }}</dd></div>
                <div><dt class="text-gray-500">Kategori</dt><dd>{{ $product->category?->name ?? '-' }}</dd></div>
                <div><dt class="text-gray-500">Harga Jual</dt><dd>{{ rupiah($product->price) }}</dd></div>
                <div><dt class="text-gray-500">Status</dt><dd><x-badge :color="$product->is_active ? 'green' : 'gray'">{{ $product->is_active ? 'Aktif' : 'Nonaktif' }}</x-badge></dd></div>
                @if ($product->description)<div><dt class="text-gray-500">Deskripsi</dt><dd>{{ $product->description }}</dd></div>@endif
            </dl>
        </x-card>

        <x-card title="Varian & Stok" :padding="false" class="lg:col-span-2">
            <table class="table-base">
                <thead><tr><th>SKU</th><th>Ukuran</th><th>Warna</th><th class="text-right">Harga</th><th class="text-right">Stok</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($product->variants as $variant)
                        <tr>
                            <td class="font-medium">{{ $variant->sku }}</td>
                            <td>{{ $variant->size }}</td>
                            <td>{{ $variant->color ?: '-' }}</td>
                            <td class="text-right">{{ rupiah($variant->selling_price) }}</td>
                            <td class="text-right"><a href="{{ route('stock.products.card', $variant) }}" class="text-indigo-600 hover:underline">{{ qty($variant->stock) }}</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-card>

        <x-card title="Bill of Materials" :padding="false" class="lg:col-span-3">
            <table class="table-base">
                <thead><tr><th>Nama BOM</th><th>Bahan</th><th>Status</th><th class="text-right">Aksi</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($product->boms as $bom)
                        <tr>
                            <td class="font-medium">{{ $bom->name }}</td>
                            <td>{{ $bom->items->map(fn ($i) => $i->material->name)->implode(', ') }}</td>
                            <td><x-badge :color="$bom->is_active ? 'green' : 'gray'">{{ $bom->is_active ? 'Aktif' : 'Nonaktif' }}</x-badge></td>
                            <td class="text-right"><a href="{{ route('boms.show', $bom) }}" class="text-sm text-indigo-600 hover:underline">Detail</a></td>
                        </tr>
                    @empty
                        <x-empty-row colspan="4" message="Produk belum memiliki BOM. Buat BOM agar bisa diproduksi." />
                    @endforelse
                </tbody>
            </table>
        </x-card>
    </div>
</x-layouts.app>
