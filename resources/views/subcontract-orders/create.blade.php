<x-layouts.app :title="'Order Maklon - SPK '.$spk->number">
    <form method="POST" action="{{ route('subcontract-orders.store') }}" class="space-y-6"
          data-confirm="Tahap yang dipilih akan dikerjakan vendor maklon." data-confirm-icon="question" data-confirm-button="Ya, buat order">
        @csrf
        <input type="hidden" name="production_order_id" value="{{ $spk->id }}">

        <x-card title="Informasi Order Maklon">
            @if ($vendors->isEmpty())
                <div class="mb-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">
                    Belum ada vendor maklon. Tandai supplier sebagai "Vendor maklon" di
                    @can('suppliers.manage')<a href="{{ route('suppliers.index') }}" class="font-medium underline">master supplier</a>@else master supplier @endcan.
                </div>
            @endif
            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <div>
                    <span class="form-label">SPK</span>
                    <p class="py-2 text-sm font-medium">{{ $spk->number }} · {{ $spk->product->name }}</p>
                    <p class="text-xs text-gray-500">{{ qty($spk->quantity) }} pcs · {{ $spk->status->label() }}</p>
                </div>
                <x-form.select name="supplier_id" label="Vendor Maklon" required data-select class="md:col-span-1"
                    :options="$vendors->mapWithKeys(fn ($v) => [$v->id => $v->name])" />
                <x-form.input name="order_date" label="Tanggal Order" :value="now()" required data-date />
                <x-form.input name="due_date" label="Target Kembali" :value="$spk->due_date" data-date />
                <x-form.input name="quantity" type="number" min="1" step="1" label="Jumlah Dimaklonkan (pcs)" :value="(float) $spk->quantity" required />
                <x-form.input name="rate" label="Ongkos Jasa per pcs (Rp)" :value="(float) $spk->bom?->labor_cost" required data-money inputmode="numeric" help="Dibayar untuk hasil bagus; reject tidak dibayar." />
                <x-form.textarea name="notes" label="Catatan / Instruksi ke Vendor" rows="2" class="md:col-span-2" />
            </div>
        </x-card>

        <x-card title="Tahap yang Dikerjakan Vendor">
            @if ($availableStages->isEmpty())
                <p class="text-sm text-gray-500">Semua tahap sudah selesai atau sudah dimaklonkan.</p>
            @else
                <div class="flex flex-wrap gap-3">
                    @foreach ($availableStages as $stage)
                        <label class="inline-flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm">
                            <input type="checkbox" name="stages[]" value="{{ $stage->stage->value }}" class="rounded border-gray-300 text-indigo-600"
                                   @checked(in_array($stage->stage->value, old('stages', ['sewing']), true))>
                            {{ $stage->sequence }}. {{ $stage->stage->label() }}
                        </label>
                    @endforeach
                </div>
                <p class="mt-3 text-xs text-gray-500">Contoh: hanya jahit (CMT jahit), atau centang semua untuk full maklon (cutting sampai packing). Tahap lain tetap dikerjakan internal.</p>
            @endif
            @foreach ($spk->stages->whereNotNull('subcontract_order_id') as $stage)
                <p class="mt-2 text-xs text-amber-700">{{ $stage->stage->label() }} sudah dimaklonkan ke {{ $stage->subcontractOrder->supplier->name }} ({{ $stage->subcontractOrder->number }}).</p>
            @endforeach
        </x-card>

        <x-card title="Rencana Bahan yang Dikirim ke Vendor" :padding="false">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>Bahan</th><th class="text-right">Kebutuhan SPK</th><th class="text-right">Sudah Dikeluarkan</th><th class="text-right">Stok Gudang</th><th class="w-44">Rencana Kirim</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($spk->materials as $line)
                            <tr>
                                <td>{{ $line->material->display_name }}</td>
                                <td class="text-right">{{ qty($line->required_quantity) }} {{ $line->material->unit->code }}</td>
                                <td class="text-right">{{ qty($line->issued_quantity) }}</td>
                                <td class="text-right">{{ qty($line->material->stock) }}</td>
                                <td><input type="number" step="0.01" min="0" name="materials[{{ $line->material_id }}]" value="{{ old('materials.'.$line->material_id, $suggested[$line->material_id] ?? 0) }}" class="form-control"></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="border-t border-gray-100 px-5 py-3 text-xs text-gray-500">
                Bahan yang direncanakan ke vendor tidak ikut dikeluarkan saat SPK dimulai; bahan dikirim lewat surat jalan bahan maklon. Isi 0 untuk bahan yang dipakai internal
                (mis. kain sudah dipotong sendiri, yang dikirim hanya potongan + aksesoris).
            </p>
        </x-card>

        <div class="flex justify-end gap-2">
            <a href="{{ route('production-orders.show', $spk) }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary" @disabled($availableStages->isEmpty() || $vendors->isEmpty())>Simpan Order Maklon</button>
        </div>
    </form>
</x-layouts.app>
