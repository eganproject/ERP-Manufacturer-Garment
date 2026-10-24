@php
    use App\Enums\ProductionOrderStatus;
    use App\Enums\StageStatus;
    $inProgress = $order->status === ProductionOrderStatus::InProgress;
    $allStagesDone = $order->stages->every(fn ($s) => $s->status === StageStatus::Done);
@endphp

<x-layouts.app :title="'SPK '.$order->number">
    <x-slot:actions>
        @if ($order->isEditable())
            <x-delete-button :action="route('production-orders.destroy', $order)" class="btn-secondary !text-red-600" />
            <x-action-form :action="route('production-orders.cancel', $order)" confirm="SPK akan dibatalkan." button="Ya, batalkan">Batalkan</x-action-form>
            <a href="{{ route('production-orders.edit', $order) }}" class="btn-secondary">Ubah</a>
            <x-action-form :action="route('production-orders.start', $order)" confirm="Bahan baku akan dikeluarkan dari gudang sesuai kebutuhan BOM." icon="question" button="Ya, mulai produksi" class="btn-primary">Mulai Produksi</x-action-form>
        @endif
    </x-slot:actions>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Informasi">
            <dl class="space-y-3 text-sm">
                <div><dt class="text-gray-500">Status</dt><dd><x-status-badge :status="$order->status" /></dd></div>
                <div><dt class="text-gray-500">Produk</dt><dd class="font-medium">{{ $order->product->code }} - {{ $order->product->name }}</dd></div>
                <div><dt class="text-gray-500">BOM</dt><dd><a href="{{ route('boms.show', $order->bom) }}" class="text-indigo-600 hover:underline">{{ $order->bom->name }}</a></dd></div>
                <div><dt class="text-gray-500">Sales Order</dt><dd>
                    @if ($order->salesOrder)<a href="{{ route('sales-orders.show', $order->salesOrder) }}" class="text-indigo-600 hover:underline">{{ $order->salesOrder->number }}</a> · {{ $order->salesOrder->customer->name }}@else - @endif
                </dd></div>
                <div class="grid grid-cols-2 gap-2">
                    <div><dt class="text-gray-500">Mulai</dt><dd>{{ tanggal($order->start_date) }}</dd></div>
                    <div><dt class="text-gray-500">Target</dt><dd>{{ tanggal($order->due_date) }}</dd></div>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <div><dt class="text-gray-500">Rencana</dt><dd class="font-semibold">{{ qty($order->quantity) }}</dd></div>
                    <div><dt class="text-gray-500">Jadi</dt><dd class="font-semibold text-emerald-600">{{ qty($order->finished_quantity) }}</dd></div>
                    <div><dt class="text-gray-500">Reject</dt><dd class="font-semibold text-red-600">{{ qty($order->reject_quantity) }}</dd></div>
                </div>
                @if ($order->notes)<div><dt class="text-gray-500">Catatan</dt><dd>{{ $order->notes }}</dd></div>@endif
            </dl>
        </x-card>

        <x-card title="Rincian per Ukuran" :padding="false" class="lg:col-span-2">
            <form method="POST" action="{{ route('production-orders.complete', $order) }}" data-confirm="Barang jadi akan dimasukkan ke stok dan SPK ditutup." data-confirm-icon="question" data-confirm-button="Ya, selesaikan">
                @csrf
                <table class="table-base">
                    <thead><tr><th>SKU</th><th>Ukuran / Warna</th><th class="text-right">Rencana</th><th class="w-40 text-right">Jadi</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($order->items as $item)
                            <tr>
                                <td class="font-medium">{{ $item->variant->sku }}</td>
                                <td>{{ $item->variant->size }}{{ $item->variant->color ? ' / '.$item->variant->color : '' }}</td>
                                <td class="text-right">{{ qty($item->quantity) }}</td>
                                <td class="text-right">
                                    @if ($inProgress && $allStagesDone)
                                        <input type="number" min="0" max="{{ (float) $item->quantity }}" step="1" name="finished[{{ $item->id }}]" value="{{ old('finished.'.$item->id, (float) $item->quantity) }}" class="form-control text-right">
                                    @else
                                        {{ qty($item->finished_quantity) }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if ($inProgress && $allStagesDone)
                    <div class="flex flex-wrap items-end justify-end gap-3 border-t border-gray-200 p-4">
                        <div class="w-40"><x-form.input name="reject_quantity" type="number" min="0" step="1" label="Total Reject" :value="$order->stages->sum('reject_quantity')" /></div>
                        <button type="submit" class="btn-success">Selesaikan Produksi</button>
                    </div>
                @elseif ($inProgress)
                    <p class="border-t border-gray-200 p-4 text-sm text-gray-500">Selesaikan semua tahapan untuk memasukkan hasil produksi ke stok.</p>
                @endif
            </form>
        </x-card>

        <x-card title="Tahapan Produksi" class="lg:col-span-3">
            <ol class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-5">
                @foreach ($order->stages as $stage)
                    <li class="rounded-lg border border-gray-200 p-4" x-data="{ edit: false }">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <p class="text-xs text-gray-500">Tahap {{ $stage->sequence }}</p>
                                <p class="font-semibold text-gray-800">{{ $stage->stage->label() }}</p>
                            </div>
                            <x-badge :color="$stage->status->color()">{{ $stage->status->label() }}</x-badge>
                        </div>
                        <dl class="mt-3 space-y-1 text-xs text-gray-600">
                            <div class="flex justify-between"><dt>Output</dt><dd class="font-medium">{{ qty($stage->output_quantity) }}</dd></div>
                            <div class="flex justify-between"><dt>Reject</dt><dd class="font-medium">{{ qty($stage->reject_quantity) }}</dd></div>
                            <div class="flex justify-between"><dt>PIC</dt><dd>{{ $stage->pic ?: '-' }}</dd></div>
                            @if ($stage->finished_at)<div class="flex justify-between"><dt>Selesai</dt><dd>{{ tanggal($stage->finished_at, 'd M H:i') }}</dd></div>@endif
                        </dl>
                        @if ($inProgress)
                            <button type="button" class="mt-3 text-xs font-medium text-indigo-600 hover:underline" @click="edit = !edit" x-text="edit ? 'Tutup' : 'Perbarui'"></button>
                            <form x-cloak x-show="edit" method="POST" action="{{ route('production-orders.stages.update', [$order, $stage]) }}" class="mt-3 space-y-2">
                                @csrf
                                @method('PUT')
                                <select name="status" class="form-control py-1.5">
                                    @foreach ($stageStatuses as $status)
                                        <option value="{{ $status->value }}" @selected($stage->status === $status)>{{ $status->label() }}</option>
                                    @endforeach
                                </select>
                                <input type="number" min="0" step="1" name="output_quantity" value="{{ (float) ($stage->output_quantity ?: $order->quantity) }}" class="form-control py-1.5" placeholder="Output">
                                <input type="number" min="0" step="1" name="reject_quantity" value="{{ (float) $stage->reject_quantity }}" class="form-control py-1.5" placeholder="Reject">
                                <input type="text" name="pic" value="{{ $stage->pic }}" class="form-control py-1.5" placeholder="PIC / Operator">
                                <textarea name="notes" rows="2" class="form-control py-1.5" placeholder="Catatan">{{ $stage->notes }}</textarea>
                                <button type="submit" class="btn-primary w-full py-1.5">Simpan</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ol>
        </x-card>

        <x-card title="Kebutuhan & Pemakaian Bahan" :padding="false" class="lg:col-span-3">
            <table class="table-base">
                <thead><tr><th>Bahan</th><th class="text-right">Kebutuhan</th><th class="text-right">Dikeluarkan</th><th class="text-right">Stok Gudang</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($order->materials as $line)
                        <tr>
                            <td>{{ $line->material->display_name }}</td>
                            <td class="text-right">{{ qty($line->required_quantity) }} {{ $line->material->unit->code }}</td>
                            <td class="text-right">{{ qty($line->issued_quantity) }}</td>
                            <td @class(['text-right', 'text-red-600 font-semibold' => $order->isEditable() && $line->material->stock < $line->required_quantity])>{{ qty($line->material->stock) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-card>
    </div>
</x-layouts.app>
