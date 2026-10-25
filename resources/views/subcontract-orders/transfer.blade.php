@php
    use App\Enums\SubcontractTransferType;
    $isDispatch = $type === SubcontractTransferType::Dispatch;
@endphp
<x-layouts.app :title="$type->label().' - '.$order->number">
    <form method="POST" action="{{ route('subcontract-orders.transfer.store', [$order, $type->value]) }}" class="space-y-6"
          data-confirm="{{ $isDispatch ? 'Stok gudang berkurang dan bahan tercatat sebagai titipan di vendor.' : 'Stok gudang bertambah dan saldo bahan di vendor berkurang.' }}" data-confirm-icon="question" data-confirm-button="Ya, simpan">
        @csrf
        <x-card :title="$isDispatch ? 'Surat Jalan Bahan ke Vendor' : 'Retur Sisa Bahan dari Vendor'">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <div>
                    <span class="form-label">Vendor</span>
                    <p class="py-2 text-sm font-medium">{{ $order->supplier->name }}</p>
                    <p class="text-xs text-gray-500">{{ $order->number }} · SPK {{ $order->productionOrder->number }}</p>
                </div>
                <x-form.input name="date" label="Tanggal" :value="now()" required data-date />
                <x-form.warehouse :label="$isDispatch ? 'Dari Gudang' : 'Masuk ke Gudang'" />
                <x-form.input name="vehicle_number" label="No. Kendaraan" />
                <x-form.input name="driver_name" label="Sopir / Pengantar" />
                <x-form.textarea name="notes" label="Catatan" rows="2" class="md:col-span-4" />
            </div>
        </x-card>

        <x-card title="Bahan" :padding="false" x-data>
            <x-slot:header>
                <button type="button" class="btn-secondary py-1.5" @click="document.querySelectorAll('[data-suggest]').forEach(el => el.value = el.dataset.suggest)">{{ $isDispatch ? 'Isi sesuai sisa rencana' : 'Isi semua saldo' }}</button>
            </x-slot:header>
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Bahan</th><th class="text-right">Rencana</th><th class="text-right">Sudah Dikirim</th><th class="text-right">Diretur</th><th class="text-right">Saldo di Vendor</th>
                            @if ($isDispatch)<th class="text-right">Stok Gudang</th>@endif
                            <th class="w-44">{{ $isDispatch ? 'Kirim Sekarang' : 'Retur Sekarang' }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($order->materials as $line)
                            @php $suggest = $isDispatch ? $line->unsentQuantity() : $line->balance(); @endphp
                            @if ($isDispatch || $line->balance() > 0)
                                <tr>
                                    <td>{{ $line->material->display_name }}</td>
                                    <td class="text-right">{{ qty($line->planned_quantity) }} {{ $line->material->unit?->code }}</td>
                                    <td class="text-right">{{ qty($line->sent_quantity) }}</td>
                                    <td class="text-right">{{ qty($line->returned_quantity) }}</td>
                                    <td class="text-right font-medium">{{ qty($line->balance()) }}</td>
                                    @if ($isDispatch)<td class="text-right">{{ qty($line->material->stock) }}</td>@endif
                                    <td><input type="number" step="0.01" min="0" @unless ($isDispatch) max="{{ $line->balance() }}" @endunless name="items[{{ $line->id }}]" value="{{ old('items.'.$line->id) }}" data-suggest="{{ $suggest }}" class="form-control"></td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="flex justify-end gap-2">
            <a href="{{ route('subcontract-orders.show', $order) }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary">Simpan</button>
        </div>
    </form>
</x-layouts.app>
