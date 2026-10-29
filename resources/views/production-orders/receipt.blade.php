@php
    $lastStage = $order->stages->sortBy('sequence')->last();
    $received = (float) $order->items->sum('finished_quantity');
@endphp
<x-layouts.app title="Setor Hasil Produksi" :subtitle="'SPK '.$order->number.' · '.$order->product->name">
    <x-guide title="Setor hasil produksi bertahap" :steps="['Pakai saat sebagian hasil sudah **selesai packing** dan perlu segera masuk gudang (mis. untuk pesanan PO marketplace), tanpa menunggu seluruh SPK selesai.', 'Isi pcs **bagus** (masuk stok jual) dan **grade B** (reject yang masih bisa dijual murah / diperbaiki → masuk stok Barang Cacat).', 'Nilainya memakai **HPP sementara** (perkiraan biaya penuh SPK ÷ rencana pcs). Saat SPK diselesaikan, setoran penutup menyerap selisih ke HPP aktual.']"></x-guide>

    <form method="POST" action="{{ route('production-orders.receipts.store', $order) }}" class="space-y-6"
          data-confirm="Barang masuk stok gudang dengan HPP sementara." data-confirm-icon="question" data-confirm-button="Ya, setor">
        @csrf
        <x-card title="Setoran">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <x-form.input name="date" label="Tanggal Setor" :value="now()" tip="Tanggal barang diterima gudang. Tercatat di kartu stok dan jurnal." required data-date />
                <x-form.warehouse :value="$order->warehouse_id" label="Masuk ke Gudang" tip="Gudang yang menerima barang jadi. Untuk pesanan marketplace, pilih gudang sumber stok toko." />
                @can('costing.view')
                    <div>
                        <span class="form-label flex items-center gap-1">HPP sementara <x-tip text="Perkiraan biaya penuh SPK (bahan + upah/borongan sesuai tarif + jasa maklon + overhead) dibagi jumlah rencana. Dikoreksi saat SPK diselesaikan." /></span>
                        <p class="py-2 text-sm font-semibold">{{ rupiah($estimatedUnitCost) }} / pcs</p>
                    </div>
                @endcan
                <div>
                    <span class="form-label">Sudah disetor</span>
                    <p class="py-2 text-sm font-semibold">{{ qty($order->receivedQuantity()) }} / {{ qty($order->quantity) }} pcs</p>
                    @if ($lastStage && (float) $lastStage->output_quantity > 0)
                        <p class="text-xs text-gray-500">Output {{ $lastStage->stage->label() }}: {{ qty($lastStage->output_quantity) }} pcs (batas pcs bagus)</p>
                    @endif
                </div>
                <x-form.textarea name="notes" label="Catatan" rows="2" class="md:col-span-4" />
            </div>
        </x-card>

        <x-card title="Jumlah per Ukuran" :padding="false">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>SKU</th><th>Ukuran / Warna</th><th class="text-right">Rencana</th><th class="text-right">Sudah Disetor</th><th class="text-right">Sisa</th>
                            <th class="w-36">Bagus</th>
                            <th class="w-36"><span class="inline-flex items-center gap-1">Grade B <x-tip title="Grade B" text="Reject yang masih punya nilai (bisa diperbaiki atau dijual murah). Masuk stok Barang Cacat, tidak bisa dikirim ke pelanggan sebelum ditangani. Reject yang dibuang tidak perlu diisi." /></span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($order->items as $item)
                            <tr>
                                <td class="font-medium">{{ $item->variant->sku }}</td>
                                <td>{{ $item->variant->size }}{{ $item->variant->color ? ' / '.$item->variant->color : '' }}</td>
                                <td class="text-right">{{ qty($item->quantity) }}</td>
                                <td class="text-right">{{ qty($item->finished_quantity) }}@if ((float) $item->defect_quantity > 0)<span class="text-xs text-amber-600"> + {{ qty($item->defect_quantity) }} B</span>@endif</td>
                                <td class="text-right font-medium">{{ qty($item->remainingQuantity()) }}</td>
                                <td><input type="number" min="0" step="1" max="{{ $item->remainingQuantity() }}" name="items[{{ $item->id }}][good]" value="{{ old('items.'.$item->id.'.good') }}" class="form-control text-right" aria-label="Bagus {{ $item->variant->sku }}" @disabled($item->remainingQuantity() <= 0)></td>
                                <td><input type="number" min="0" step="1" max="{{ $item->remainingQuantity() }}" name="items[{{ $item->id }}][defect]" value="{{ old('items.'.$item->id.'.defect') }}" class="form-control text-right" aria-label="Grade B {{ $item->variant->sku }}" @disabled($item->remainingQuantity() <= 0)></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="flex justify-end gap-2">
            <a href="{{ route('production-orders.show', $order) }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary">Setor ke Gudang</button>
        </div>
    </form>
</x-layouts.app>
