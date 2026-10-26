@php
    $canPurchase = auth()->user()->can('purchase.manage');
    $canProduce = auth()->user()->can('production.manage');
    $shortages = $materials->where('shortage', '>', 0);
    $estimate = $shortages->sum(fn ($row) => $row['suggested_quantity'] * $row['price']);
    $initial = $materials->values()->map(fn ($row) => [
        'selected' => $row['shortage'] > 0,
        'quantity' => $row['suggested_quantity'],
        'price' => $row['price'],
    ]);
@endphp
<x-layouts.app title="MRP / Kebutuhan Bahan">
    <x-guide title="Cara kerja MRP" :steps="['MRP menghitung **barang jadi** yang masih kurang dari Sales Order terbuka, lalu menghitung **bahan** yang dibutuhkan lewat BOM.', 'Kebutuhan dibandingkan dengan stok, PO yang sedang berjalan dan (opsional) stok minimum.', 'Centang bahan yang kurang lalu klik **Buat PO Draft**: PO otomatis dikelompokkan per supplier utama bahan.']"></x-guide>
    <x-card class="mb-6">
        <form method="GET" class="flex flex-wrap items-end gap-3">
            <input type="hidden" name="filtered" value="1">
            <div class="min-w-72 flex-1">
                <x-form.label for="f_sales_order_ids" label="Sales Order" tip="Batasi perhitungan ke SO tertentu. Kosongkan untuk menghitung semua SO terbuka (dikonfirmasi/terkirim sebagian) dan SPK draft." />
                <select id="f_sales_order_ids" name="sales_order_ids[]" multiple data-select data-placeholder="Semua SO terbuka" class="form-control">
                    @foreach ($salesOrders as $so)
                        <option value="{{ $so->id }}" @selected(in_array($so->id, $selectedSalesOrders, true))>{{ $so->number }} · {{ $so->customer->name }} ({{ tanggal($so->delivery_date ?? $so->order_date) }})</option>
                    @endforeach
                </select>
            </div>
            <label class="flex items-center gap-2 pb-2 text-sm text-gray-700">
                <input type="checkbox" name="safety" value="1" @checked($includeSafety) class="rounded border-gray-300 text-indigo-600">
                Jaga stok minimum
            </label>
            <x-tip title="Jaga stok minimum" text="Bila dicentang, kebutuhan ditambah agar stok setelah produksi tidak turun di bawah stok minimum bahan." class="mb-2.5 -ml-1" />
            <button type="submit" class="btn-secondary">Hitung Ulang</button>
            @if (request()->has('filtered') || request()->has('sales_order_ids'))
                <a href="{{ route('mrp.index') }}" class="pb-2 text-sm text-gray-500 hover:text-gray-700">Reset</a>
            @endif
        </form>
        <p class="mt-3 text-xs text-gray-500">
            Kebutuhan barang jadi = sisa SO − stok barang jadi − rencana SPK berjalan. Kekurangan bahan = kebutuhan (BOM aktif + SPK draft) + stok minimum − stok gudang − PO yang belum diterima (termasuk PO draft / menunggu persetujuan).
        </p>
    </x-card>

    @foreach ($warnings as $warning)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">{{ $warning }}</div>
    @endforeach

    <x-summary-tiles :tiles="[
        ['SO terbuka yang dihitung', $sales_order_count.' SO'],
        ['Bahan yang perlu dibeli', $shortages->count().' item', $shortages->isNotEmpty() ? 'red' : null],
        ['Estimasi nilai pembelian', rupiah($estimate), $estimate > 0 ? 'amber' : null],
    ]" />

    <x-card title="Kebutuhan Barang Jadi" :padding="false" class="mb-6">
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>Produk</th><th>SKU / Ukuran</th><th class="text-right">Sisa SO</th><th class="text-right">Stok Jadi</th><th class="text-right">Dalam SPK</th><th class="text-right">Perlu Diproduksi</th><th></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($products as $row)
                        <tr>
                            <td>{{ $row['product']->code }} - {{ $row['product']->name }}</td>
                            <td>{{ $row['variant']->sku }} <span class="text-gray-500">· {{ $row['variant']->size }}{{ $row['variant']->color ? ' / '.$row['variant']->color : '' }}</span></td>
                            <td class="text-right">{{ qty($row['ordered']) }}</td>
                            <td class="text-right">{{ qty($row['stock']) }}</td>
                            <td class="text-right">{{ qty($row['planned']) }}</td>
                            <td @class(['text-right font-semibold', 'text-red-600' => $row['net'] > 0, 'text-gray-400' => $row['net'] <= 0])>{{ qty($row['net']) }}</td>
                            <td class="text-right">
                                @if ($row['net'] > 0 && $canProduce)
                                    <a href="{{ route('production-orders.create', ['product_id' => $row['product']->id]) }}" class="text-xs font-medium text-indigo-600 hover:underline">Buat SPK</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-empty-row colspan="7" message="Tidak ada SO terbuka yang masih menunggu pengiriman." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>

    <form method="POST" action="{{ route('mrp.purchase-orders') }}" x-data="{ rows: @js($initial) }"
          data-confirm="PO draft akan dibuat per supplier untuk bahan yang dicentang." data-confirm-icon="question" data-confirm-button="Ya, buat PO draft">
        @csrf
        <x-card title="Kebutuhan Bahan Baku" :padding="false">
            @if ($canPurchase && $materials->isNotEmpty())
                <x-slot:header>
                    <span class="text-sm text-gray-600">Dipilih: <span class="font-semibold" x-text="rows.filter(r => r.selected).length"></span> bahan ·
                        <span class="font-semibold" x-text="'Rp ' + ERP.formatNumber(rows.filter(r => r.selected).reduce((s, r) => s + (Number(r.quantity) || 0) * (Number(r.price) || 0), 0))"></span></span>
                </x-slot:header>
            @endif
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead>
                        <tr>
                            @if ($canPurchase)<th class="w-10"></th>@endif
                            <th class="min-w-56">Bahan</th>
                            <th class="text-right">Kebutuhan</th>
                            <th class="text-right">Stok Min.</th>
                            <th class="text-right">Stok</th>
                            <th class="text-right">PO Berjalan</th>
                            <th class="text-right">PO Draft</th>
                            <th class="text-right">Kekurangan</th>
                            @if ($canPurchase)
                                <th class="min-w-52">Supplier</th>
                                <th class="min-w-28">Qty Beli</th>
                                <th class="min-w-32">Harga</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($materials as $i => $row)
                            @php $m = $row['material']; @endphp
                            <tr :class="rows[{{ $i }}].selected ? 'bg-indigo-50/40' : ''">
                                @if ($canPurchase)
                                    <td>
                                        <input type="hidden" name="lines[{{ $i }}][material_id]" value="{{ $m->id }}">
                                        <input type="checkbox" name="lines[{{ $i }}][selected]" value="1" x-model="rows[{{ $i }}].selected" class="rounded border-gray-300 text-indigo-600">
                                    </td>
                                @endif
                                <td>
                                    <a href="{{ route('stock.materials.card', $m) }}" class="font-medium text-indigo-600 hover:underline">{{ $m->display_name }}</a>
                                    <div class="text-xs text-gray-500">
                                        @if ($row['from_sales'] > 0) SO {{ qty($row['from_sales']) }} @endif
                                        @if ($row['from_draft_spk'] > 0) · SPK draft {{ qty($row['from_draft_spk']) }} @endif
                                    </div>
                                </td>
                                <td class="text-right whitespace-nowrap">{{ qty($row['required']) }} {{ $m->unit->code }}</td>
                                <td class="text-right">{{ qty($row['safety']) }}</td>
                                <td class="text-right">{{ qty($row['stock']) }}</td>
                                <td class="text-right">{{ qty($row['on_order']) }}</td>
                                <td class="text-right">{{ qty($row['draft_po']) }}</td>
                                <td @class(['text-right font-semibold whitespace-nowrap', 'text-red-600' => $row['shortage'] > 0, 'text-emerald-600' => $row['shortage'] <= 0])>
                                    {{ $row['shortage'] > 0 ? qty($row['shortage']).' '.$m->unit->code : 'Cukup' }}
                                </td>
                                @if ($canPurchase)
                                    <td>
                                        <select name="lines[{{ $i }}][supplier_id]" class="form-control py-1.5" data-select data-placeholder="Pilih supplier...">
                                            <option value="">Pilih supplier...</option>
                                            @foreach ($suppliers as $id => $name)
                                                <option value="{{ $id }}" @selected((int) $row['supplier_id'] === (int) $id)>{{ $name }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td><input type="number" min="0" step="0.01" name="lines[{{ $i }}][quantity]" x-model="rows[{{ $i }}].quantity" class="form-control py-1.5 text-right"></td>
                                    <td><input type="number" min="0" step="1" name="lines[{{ $i }}][price]" x-model="rows[{{ $i }}].price" class="form-control py-1.5 text-right"></td>
                                @endif
                            </tr>
                        @empty
                            <x-empty-row :colspan="$canPurchase ? 11 : 7" message="Tidak ada kebutuhan bahan dari SO terbuka maupun SPK draft." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if ($canPurchase && $materials->isNotEmpty())
                <div class="flex flex-wrap items-end justify-end gap-3 border-t border-gray-200 p-4">
                    <div class="min-w-72 flex-1"><x-form.input name="notes" label="Catatan PO" placeholder="Opsional, default: Dibuat otomatis dari MRP" /></div>
                    <button type="submit" class="btn-primary" :disabled="! rows.some(r => r.selected)">Buat PO Draft</button>
                </div>
            @endif
        </x-card>
    </form>
</x-layouts.app>
