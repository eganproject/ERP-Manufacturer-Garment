<x-layouts.app :title="'Terima Hasil Maklon - '.$order->number">
    @if ($pendingStages)
        <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            Tahap <strong>{{ $pendingStages }}</strong> di SPK
            <a href="{{ route('production-orders.show', $order->productionOrder) }}" class="font-medium underline">{{ $order->productionOrder->number }}</a>
            belum selesai. Hasil maklon baru bisa diterima setelah tahap sebelumnya ditandai selesai.
        </div>
    @endif
    <form method="POST" action="{{ route('subcontract-orders.receive.store', $order) }}" class="space-y-6"
          x-data="{ good: {{ (float) old('good_quantity', $order->outstandingQuantity()) }}, rate: {{ (float) $order->rate }} }"
          data-confirm="Hasil diterima dan ongkos jasa dibebankan ke HPP SPK." data-confirm-icon="question" data-confirm-button="Ya, simpan">
        @csrf
        <x-card title="Penerimaan Hasil dari Vendor">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <div>
                    <span class="form-label">Vendor</span>
                    <p class="py-2 text-sm font-medium">{{ $order->supplier->name }}</p>
                    <p class="text-xs text-gray-500">{{ $order->stagesLabel() }} · SPK {{ $order->productionOrder->number }}</p>
                </div>
                <div>
                    <span class="form-label">Sisa di vendor</span>
                    <p class="py-2 text-sm font-semibold">{{ qty($order->outstandingQuantity()) }} pcs</p>
                    <p class="text-xs text-gray-500">dari {{ qty($order->quantity) }} pcs order</p>
                </div>
                <x-form.input name="date" label="Tanggal Terima" :value="now()" required data-date />
                <x-form.input name="vendor_reference" label="No. Surat Jalan Vendor" />
                <x-form.input name="good_quantity" type="number" min="0" step="1" label="Hasil Bagus (pcs)" required x-model.number="good" />
                <x-form.input name="reject_quantity" type="number" min="0" step="1" label="Reject (pcs)" :value="0" />
                <div>
                    <span class="form-label">Ongkos jasa</span>
                    <p class="py-2 text-sm font-semibold" x-text="'Rp ' + ERP.formatNumber((good || 0) * rate)"></p>
                    <p class="text-xs text-gray-500">{{ rupiah($order->rate) }} / pcs bagus</p>
                </div>
                <x-form.textarea name="notes" label="Catatan" rows="2" />
            </div>
        </x-card>

        <div class="flex justify-end gap-2">
            <a href="{{ route('subcontract-orders.show', $order) }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-success">Simpan Penerimaan</button>
        </div>
    </form>
</x-layouts.app>
