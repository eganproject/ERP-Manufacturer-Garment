<x-layouts.app :title="'BOM: '.$bom->name">
    <x-slot:actions>
        <a href="{{ route('boms.edit', $bom) }}" class="btn-primary">Ubah BOM</a>
    </x-slot:actions>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Informasi">
            <dl class="space-y-3 text-sm">
                <div><dt class="text-gray-500">Produk</dt><dd class="font-medium"><a href="{{ route('products.show', $bom->product) }}" class="text-indigo-600 hover:underline">{{ $bom->product->code }} - {{ $bom->product->name }}</a></dd></div>
                <div><dt class="text-gray-500">Status</dt><dd><x-badge :color="$bom->is_active ? 'green' : 'gray'">{{ $bom->is_active ? 'Aktif' : 'Nonaktif' }}</x-badge></dd></div>
                @if ($bom->notes)<div><dt class="text-gray-500">Catatan</dt><dd>{{ $bom->notes }}</dd></div>@endif
            </dl>
        </x-card>

        <x-card title="Kebutuhan bahan per 1 pcs" :padding="false" class="lg:col-span-2" x-data="{ qty: 100 }">
            <x-slot:header>
                <label class="flex items-center gap-2 text-sm text-gray-600">Simulasi untuk
                    <input type="number" min="1" x-model.number="qty" class="form-control w-24 py-1"> pcs
                </label>
            </x-slot:header>
            <table class="table-base">
                <thead><tr><th>Bahan</th><th class="text-right">Qty / pcs</th><th class="text-right">Waste</th><th class="text-right">Total kebutuhan</th><th class="text-right">Estimasi biaya</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($bom->items as $item)
                        @php $factor = (float) $item->quantity * (1 + (float) $item->waste_percent / 100); @endphp
                        <tr>
                            <td>{{ $item->material->display_name }}@if ($item->notes)<div class="text-xs text-gray-500">{{ $item->notes }}</div>@endif</td>
                            <td class="text-right">{{ qty($item->quantity, 4) }} {{ $item->material->unit->code }}</td>
                            <td class="text-right">{{ qty($item->waste_percent) }}%</td>
                            <td class="text-right font-medium" x-text="ERP.formatNumber({{ $factor }} * qty, 2) + ' {{ $item->material->unit->code }}'"></td>
                            <td class="text-right" x-text="'Rp ' + ERP.formatNumber({{ $factor * (float) $item->material->price }} * qty)"></td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="bg-gray-50 font-semibold">
                        <td colspan="4" class="px-4 py-3 text-right">Estimasi biaya bahan</td>
                        <td class="px-4 py-3 text-right" x-text="'Rp ' + ERP.formatNumber({{ $bom->items->sum(fn ($i) => (float) $i->quantity * (1 + (float) $i->waste_percent / 100) * (float) $i->material->price) }} * qty)"></td>
                    </tr>
                </tfoot>
            </table>
        </x-card>
    </div>
</x-layouts.app>
