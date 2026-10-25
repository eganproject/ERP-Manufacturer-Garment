<x-layouts.app title="Stok Marketplace">
    @if ($store)
        <x-slot:actions><a href="{{ route('marketplace-stock.index', ['store_id' => $store->id, 'export' => 'csv']) }}" class="btn-secondary">Unduh CSV Stok Tersedia</a></x-slot:actions>
    @endif

    <x-card class="mb-4">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div class="w-72"><x-form.select name="store_id" label="Toko" :options="$stores->mapWithKeys(fn ($s) => [$s->id => $s->display_name])" :value="$store?->id" :placeholder="false" onchange="this.form.submit()" /></div>
        </form>
        <p class="mt-2 text-xs text-gray-500">
            Stok tersedia = stok jual di gudang toko{{ $store ? ' ('.$store->warehouse->name.')' : '' }} − pesanan Perlu Dikirim (semua toko yang memakai gudang yang sama), dibagi isi paket.
            Unggah angka ini ke Seller Center (ubah stok massal) agar tidak terjadi oversell.
        </p>
    </x-card>

    <x-card :padding="false">
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>SKU Marketplace</th><th>Varian ERP</th><th class="text-right">Isi/Paket</th><th class="text-right">Stok Gudang (pcs)</th><th class="text-right">Dialokasikan (pcs)</th><th class="text-right">Stok Tersedia</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($rows as $row)
                        <tr>
                            <td class="font-medium">{{ $row['sku'] }}@if ($row['name'])<div class="text-xs text-gray-500">{{ $row['name'] }}</div>@endif</td>
                            <td>{{ $row['variant'] }}</td>
                            <td class="text-right">{{ qty($row['units']) }}</td>
                            <td class="text-right">{{ qty($row['stock']) }}</td>
                            <td class="text-right">{{ qty($row['reserved']) }}</td>
                            <td @class(['text-right font-semibold', 'text-red-600' => $row['available'] <= 0])>{{ qty($row['available']) }}</td>
                        </tr>
                    @empty
                        <x-empty-row colspan="6" message="Belum ada mapping SKU untuk toko ini." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</x-layouts.app>
