<x-layouts.app :title="'Terima Barang - '.$order->number">
    <form method="POST" action="{{ route('purchase-orders.receive.store', $order) }}" class="space-y-6"
          data-confirm="Stok bahan akan bertambah sesuai jumlah yang diterima." data-confirm-icon="question" data-confirm-button="Ya, simpan">
        @csrf
        <x-card title="Informasi Penerimaan">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div>
                    <span class="form-label">Supplier</span>
                    <p class="py-2 text-sm font-medium">{{ $order->supplier->name }}</p>
                </div>
                <x-form.input name="receipt_date" label="Tanggal Terima" :value="now()" required data-date />
                <x-form.input name="delivery_note" label="No. Surat Jalan Supplier" />
                <x-form.textarea name="notes" label="Catatan" rows="2" class="md:col-span-3" />
            </div>
        </x-card>

        <x-card title="Jumlah Diterima" :padding="false" x-data>
            <x-slot:header>
                <button type="button" class="btn-secondary py-1.5" @click="document.querySelectorAll('[data-outstanding]').forEach(el => el.value = el.dataset.outstanding)">Isi semua sesuai sisa</button>
            </x-slot:header>
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>Bahan</th><th class="text-right">Dipesan</th><th class="text-right">Sudah Diterima</th><th class="text-right">Sisa</th><th class="w-44">Diterima Sekarang</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($order->items as $item)
                            <tr>
                                <td>{{ $item->material->display_name }}</td>
                                <td class="text-right">{{ qty($item->quantity) }}</td>
                                <td class="text-right">{{ qty($item->received_quantity) }}</td>
                                <td class="text-right font-medium">{{ qty($item->outstandingQuantity()) }} {{ $item->material->unit->code }}</td>
                                <td>
                                    @if ($item->outstandingQuantity() > 0)
                                        <input type="number" step="0.01" min="0" max="{{ $item->outstandingQuantity() }}" name="items[{{ $item->id }}]"
                                               value="{{ old('items.'.$item->id) }}" data-outstanding="{{ $item->outstandingQuantity() }}" class="form-control">
                                    @else
                                        <x-badge color="green">Lengkap</x-badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="flex justify-end gap-2">
            <a href="{{ route('purchase-orders.show', $order) }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-success">Simpan Penerimaan</button>
        </div>
    </form>
</x-layouts.app>
