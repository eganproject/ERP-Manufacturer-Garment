@php
    use App\Enums\ProductionOrderStatus;
    use App\Enums\SubcontractTransferType;
    $spk = $order->productionOrder;
    $user = auth()->user();
    $canTransfer = $order->isOpen() && $user->can('subcontract.transfer');
    $canManage = $order->isOpen() && $user->can('subcontract.manage');
    $spkRunning = $spk->status === ProductionOrderStatus::InProgress;
    $withCost = $user->can('costing.view');
@endphp

<x-layouts.app :title="'Order Maklon '.$order->number" :subtitle="$order->supplier->name.' · '.$spk->number">
    <x-slot:actions>
        <x-more-actions>
            <a href="{{ route('subcontract-orders.pdf', $order) }}" target="_blank" class="menu-item">Cetak Order</a>
            @if ($canManage && $order->canCancel())
                <div class="menu-divider"></div>
                <x-action-form :action="route('subcontract-orders.cancel', $order)" confirm="Order maklon dibatalkan, tahap kembali dikerjakan internal." button="Ya, batalkan" class="menu-item-danger">Batalkan Order</x-action-form>
            @endif
        </x-more-actions>
        @if ($canTransfer && $spkRunning)
            <a href="{{ route('subcontract-orders.transfer', [$order, 'dispatch']) }}" class="btn-secondary">Kirim Bahan</a>
        @endif
        @if ($canTransfer && $order->hasMaterialAtVendor())
            <a href="{{ route('subcontract-orders.transfer', [$order, 'return']) }}" class="btn-secondary">Retur Sisa Bahan</a>
        @endif
        @if ($canTransfer && $spkRunning && $order->outstandingQuantity() > 0)
            <a href="{{ route('subcontract-orders.receive', $order) }}" class="btn-primary">Terima Hasil</a>
        @endif
        @if ($canManage && $order->receipts->isNotEmpty())
            <x-action-form :action="route('subcontract-orders.close', $order)" icon="question" button="Ya, tutup order" class="btn-success"
                :confirm="$order->hasMaterialAtVendor() ? 'Masih ada bahan di vendor. Saldo yang tidak diretur akan dianggap terpakai / susut dan dibebankan ke HPP SPK.' : 'Order ditutup dan tahap maklon ditandai selesai.'">Tutup Order</x-action-form>
        @endif
        @if ($order->canBill())
            @can('payables.manage')
                <a href="{{ route('bills.create', ['subcontract_order_id' => $order->id]) }}" class="btn-primary">Catat Tagihan Vendor</a>
            @endcan
        @endif
    </x-slot:actions>

    @if ($order->isOpen() && ! $spkRunning)
        <div class="mb-4 rounded-lg bg-amber-50 p-3 text-sm text-amber-800">SPK {{ $spk->number }} belum dimulai. Mulai produksi di halaman SPK agar bahan bisa dikirim ke vendor.</div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Informasi">
            <dl class="space-y-3 text-sm">
                <div><dt class="text-gray-500">Status</dt><dd><x-status-badge :status="$order->status" /></dd></div>
                <div><dt class="text-gray-500">Vendor Maklon</dt><dd class="font-medium">{{ $order->supplier->name }}</dd><dd class="text-gray-500">{{ $order->supplier->phone }}</dd></div>
                <div><dt class="text-gray-500">SPK</dt><dd><a href="{{ route('production-orders.show', $spk) }}" class="font-medium text-indigo-600 hover:underline">{{ $spk->number }}</a> · {{ $spk->product->name }}</dd></div>
                <div><dt class="text-gray-500">Tahap Dimaklonkan</dt><dd>{{ $order->stagesLabel() }}</dd></div>
                <div class="grid grid-cols-2 gap-2">
                    <div><dt class="text-gray-500">Tanggal</dt><dd>{{ tanggal($order->order_date) }}</dd></div>
                    <div><dt class="text-gray-500">Target</dt><dd @class(['text-red-600 font-medium' => $order->isOpen() && $order->due_date?->isPast()])>{{ tanggal($order->due_date) }}</dd></div>
                </div>
                <div class="grid grid-cols-3 gap-2">
                    <div><dt class="text-gray-500">Order</dt><dd class="font-semibold">{{ qty($order->quantity) }}</dd></div>
                    <div><dt class="text-gray-500">Bagus</dt><dd class="font-semibold text-emerald-600">{{ qty($order->received_quantity) }}</dd></div>
                    <div><dt class="text-gray-500">Reject</dt><dd class="font-semibold text-red-600">{{ qty($order->reject_quantity) }}</dd></div>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div><dt class="text-gray-500">Ongkos / pcs</dt><dd>{{ rupiah($order->rate) }}</dd></div>
                    <div><dt class="text-gray-500">Total Jasa</dt><dd class="font-semibold">{{ rupiah($order->service_cost) }}</dd></div>
                </div>
                <div><dt class="text-gray-500">Dibuat oleh</dt><dd>{{ $order->creator?->name ?? '-' }}</dd></div>
                @if ($order->notes)<div><dt class="text-gray-500">Catatan</dt><dd class="whitespace-pre-line">{{ $order->notes }}</dd></div>@endif
            </dl>
        </x-card>

        <x-card title="Bahan Titipan & Rekonsiliasi" :padding="false" class="lg:col-span-2">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Bahan</th><th class="text-right">Rencana</th><th class="text-right">Dikirim</th><th class="text-right">Diretur</th>
                            <th class="text-right">{{ $order->isOpen() ? 'Saldo di Vendor' : 'Terpakai' }}</th>
                            <th class="text-right">Standar</th><th class="text-right">Selisih</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($order->materials as $line)
                            @php
                                $spkLine = $spk->materials->firstWhere('material_id', $line->material_id);
                                $standard = $line->standardUsage($spkLine, (float) $spk->quantity);
                                $actual = $line->actualUsage();
                                $variance = (float) $line->sent_quantity > 0 ? round($actual - $standard, 2) : 0;
                            @endphp
                            <tr @class(['text-gray-400' => (float) $line->planned_quantity <= 0 && (float) $line->sent_quantity <= 0])>
                                <td>{{ $line->material->display_name }}</td>
                                <td class="text-right">{{ qty($line->planned_quantity) }} {{ $line->material->unit?->code }}</td>
                                <td class="text-right">{{ qty($line->sent_quantity) }}</td>
                                <td class="text-right">{{ qty($line->returned_quantity) }}</td>
                                <td class="text-right font-medium">{{ qty($order->isOpen() ? $line->balance() : $line->used_quantity) }}</td>
                                <td class="text-right">{{ qty($standard) }}</td>
                                <td @class(['text-right whitespace-nowrap', 'text-red-600 font-medium' => $variance > 0.009, 'text-emerald-600' => $variance < -0.009])>
                                    {{ $variance > 0 ? '+' : '' }}{{ qty($variance) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="border-t border-gray-100 px-5 py-3 text-xs text-gray-500">
                Standar = kebutuhan BOM per pcs × hasil yang diterima (bagus + reject). Selisih = {{ $order->isOpen() ? '(dikirim − diretur)' : 'terpakai' }} − standar;
                positif berarti boros / susut, negatif berarti sisa bahan yang seharusnya diretur vendor.
                @if ($order->isOpen()) Saat order ditutup, saldo yang tidak diretur dianggap terpakai dan tetap menjadi biaya bahan SPK. @endif
            </p>
        </x-card>

        <x-card title="Pengiriman & Retur Bahan" :padding="false" class="lg:col-span-3">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>No. Dokumen</th><th>Jenis</th><th>Tanggal</th><th class="text-right">Jumlah Item</th>@if ($withCost)<th class="text-right">Nilai Bahan</th>@endif<th>Kendaraan / Sopir</th><th class="text-right">Cetak</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($order->transfers as $transfer)
                            <tr>
                                <td class="font-medium">{{ $transfer->number }}</td>
                                <td><x-badge :color="$transfer->type->color()">{{ $transfer->type->label() }}</x-badge></td>
                                <td>{{ tanggal($transfer->date) }}<x-warehouse-tag :warehouse="$transfer->warehouse" /></td>
                                <td class="text-right">{{ $transfer->items->count() }}</td>
                                @if ($withCost)<td class="text-right whitespace-nowrap">{{ rupiah($transfer->totalValue()) }}</td>@endif
                                <td>{{ $transfer->vehicle_number ?: '-' }}{{ $transfer->driver_name ? ' · '.$transfer->driver_name : '' }}</td>
                                <td class="text-right"><a href="{{ route('subcontract-transfers.pdf', $transfer) }}" target="_blank" class="text-sm font-medium text-indigo-600 hover:underline">PDF</a></td>
                            </tr>
                        @empty
                            <x-empty-row compact :colspan="$withCost ? 7 : 6" message="Belum ada bahan yang dikirim ke vendor." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        <x-card title="Penerimaan Hasil" :padding="false" class="lg:col-span-3">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>No. Terima</th><th>Tanggal</th><th>SJ Vendor</th><th class="text-right">Bagus</th><th class="text-right">Reject</th><th class="text-right">Ongkos Jasa</th><th>Tagihan</th><th class="text-right">Cetak</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($order->receipts as $receipt)
                            <tr>
                                <td class="font-medium">{{ $receipt->number }}</td>
                                <td>{{ tanggal($receipt->date) }}</td>
                                <td>{{ $receipt->vendor_reference ?: '-' }}</td>
                                <td class="text-right text-emerald-700">{{ qty($receipt->good_quantity) }}</td>
                                <td class="text-right text-red-600">{{ qty($receipt->reject_quantity) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($receipt->amount) }}</td>
                                <td>
                                    @if ($receipt->bill)
                                        @can('payables.view')<a href="{{ route('bills.show', $receipt->bill) }}" class="text-indigo-600 hover:underline">{{ $receipt->bill->number }}</a>@else {{ $receipt->bill->number }} @endcan
                                    @elseif ((float) $receipt->amount > 0)
                                        <x-badge color="amber">Belum ditagih</x-badge>
                                    @else - @endif
                                </td>
                                <td class="text-right"><a href="{{ route('subcontract-receipts.pdf', $receipt) }}" target="_blank" class="text-sm font-medium text-indigo-600 hover:underline">PDF</a></td>
                            </tr>
                        @empty
                            <x-empty-row compact colspan="8" message="Belum ada hasil yang diterima dari vendor." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        <x-journal-entries :sources="collect([$order])->merge($order->transfers)->all()" class="lg:col-span-3" />
        <x-activity-log :activities="$order->activities" class="lg:col-span-3" />
    </div>
</x-layouts.app>
