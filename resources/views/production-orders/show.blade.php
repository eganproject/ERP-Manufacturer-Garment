@php
    use App\Enums\ProductionOrderStatus;
    use App\Enums\StageStatus;
    $inProgress = $order->status === ProductionOrderStatus::InProgress && auth()->user()->can('production.manage');
    $allStagesDone = $order->stages->every(fn ($s) => $s->status === StageStatus::Done);
@endphp

<x-layouts.app :title="$order->number" :subtitle="'SPK Produksi · '.$order->product->name.' · '.qty($order->quantity).' pcs'">
    <x-slot:actions>
        <x-more-actions>
            <a href="{{ route('production-orders.pdf', $order) }}" target="_blank" class="menu-item">Cetak SPK</a>
            @if ($inProgress)
                <a href="{{ route('production-orders.materials', [$order, 'issue']) }}" class="menu-item">Bahan Tambahan</a>
                <a href="{{ route('production-orders.materials', [$order, 'return']) }}" class="menu-item">Retur Sisa Bahan</a>
            @endif
            @if ($order->isEditable() && auth()->user()->can('production.manage'))
                <a href="{{ route('production-orders.edit', $order) }}" class="menu-item">Ubah SPK</a>
                <div class="menu-divider"></div>
                <x-action-form :action="route('production-orders.cancel', $order)" confirm="SPK akan dibatalkan." button="Ya, batalkan" class="menu-item-danger">Batalkan SPK</x-action-form>
                <x-delete-button :action="route('production-orders.destroy', $order)" label="Hapus SPK" class="menu-item-danger" />
            @endif
        </x-more-actions>
        @if ($inProgress && $order->items->contains(fn ($i) => $i->remainingQuantity() > 0))
            <a href="{{ route('production-orders.receipts.create', $order) }}" class="btn-secondary">Setor Hasil</a>
        @endif
        @if ($order->isEditable() && auth()->user()->can('production.manage'))
            <x-action-form :action="route('production-orders.start', $order)" :confirm="$order->subcontractOrders->where('status', \App\Enums\SubcontractStatus::Open)->isNotEmpty() ? 'Bahan untuk internal dikeluarkan dari gudang. Bahan yang direncanakan ke vendor maklon dikirim lewat order maklon.' : 'Bahan baku akan dikeluarkan dari gudang sesuai kebutuhan BOM. Bila ada tahap yang dikerjakan vendor maklon dengan bahan dari gudang, buat order maklon dulu agar bahannya dikirim lewat order maklon.'" icon="question" button="Ya, mulai produksi" class="btn-primary">Mulai Produksi</x-action-form>
        @endif
    </x-slot:actions>

    {{-- Petunjuk langkah berikutnya sesuai status SPK --}}
    @if ($order->status === ProductionOrderStatus::Draft)
        <x-next-step>Periksa kebutuhan bahan di bawah. Bila ada tahap yang dikerjakan vendor luar, buat <strong>Order Maklon</strong> dulu. Lalu klik <strong>Mulai Produksi</strong>: bahan dikeluarkan dari gudang dan dibebankan ke SPK.</x-next-step>
    @elseif ($order->status === ProductionOrderStatus::InProgress)
        <x-next-step>Catat <strong>hasil kerja borongan</strong> harian karyawan (untuk upah) dan klik <strong>Perbarui</strong> di tiap kartu tahapan saat tahap itu selesai (untuk progres SPK). Hasil yang sudah selesai packing bisa masuk gudang lebih dulu lewat <strong>Setor Hasil</strong>. Kain kurang / sisa bahan dicatat lewat menu <strong>Lainnya</strong>. Setelah semua tahap selesai (dan order maklon ditutup), isi sisa jumlah jadi per ukuran lalu klik <strong>Selesaikan Produksi</strong>.</x-next-step>
    @elseif ($order->status === ProductionOrderStatus::Completed)
        <x-next-step tone="green" title="Produksi selesai">Hasil produksi sudah masuk stok barang jadi dengan HPP per pcs seperti di kartu Biaya Produksi.@if ((float) $order->defect_quantity > 0) Barang grade B ada di stok <strong>Barang Cacat</strong> untuk diperbaiki, dijual murah, atau dimusnahkan.@endif</x-next-step>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Informasi">
            <dl class="space-y-3 text-sm">
                <div><dt class="text-gray-500">Status</dt><dd><x-status-badge :status="$order->status" /></dd></div>
                <div><dt class="text-gray-500">Produk</dt><dd class="font-medium">{{ $order->product->code }} - {{ $order->product->name }}</dd></div>
                <div><dt class="text-gray-500">BOM</dt><dd>@can('boms.manage')<a href="{{ route('boms.show', $order->bom) }}" class="text-indigo-600 hover:underline">{{ $order->bom->name }}</a>@else{{ $order->bom->name }}@endcan</dd></div>
                <div><dt class="text-gray-500">Sales Order</dt><dd>
                    @if ($order->salesOrder)<a href="{{ route('sales-orders.show', $order->salesOrder) }}" class="text-indigo-600 hover:underline">{{ $order->salesOrder->number }}</a> · {{ $order->salesOrder->customer->name }}@else - @endif
                </dd></div>
                <x-warehouse-tag :warehouse="$order->warehouse" block />
                <div class="grid grid-cols-2 gap-2">
                    <div><dt class="text-gray-500">Mulai</dt><dd>{{ tanggal($order->start_date) }}</dd></div>
                    <div><dt class="text-gray-500">Target</dt><dd>{{ tanggal($order->due_date) }}</dd></div>
                </div>
                <div class="grid grid-cols-4 gap-2">
                    <div><dt class="text-gray-500">Rencana</dt><dd class="font-semibold">{{ qty($order->quantity) }}</dd></div>
                    <div><dt class="text-gray-500">Jadi</dt><dd class="font-semibold text-emerald-600">{{ qty($order->items->sum('finished_quantity')) }}</dd></div>
                    <div><dt class="text-gray-500">Grade B</dt><dd class="font-semibold text-amber-600">{{ qty($order->items->sum('defect_quantity')) }}</dd></div>
                    <div><dt class="text-gray-500">Reject</dt><dd class="font-semibold text-red-600">{{ qty($order->reject_quantity) }}</dd></div>
                </div>
                @if ($order->notes)<div><dt class="text-gray-500">Catatan</dt><dd>{{ $order->notes }}</dd></div>@endif
            </dl>
        </x-card>

        @php
            // Saran setoran penutup: sisa per ukuran, dikurangi kekurangan output tahap terakhir mulai dari ukuran terbanyak
            $suggestFinished = $order->items->mapWithKeys(fn ($i) => [$i->id => $i->remainingQuantity()])->all();
            $lastStage = $order->stages->sortBy('sequence')->last();
            $lastOutput = (float) ($lastStage?->output_quantity ?? 0);
            $receivedGood = (float) $order->items->sum('finished_quantity');
            $shortfall = $lastOutput > 0 ? array_sum($suggestFinished) - max(0, $lastOutput - $receivedGood) : 0;
            foreach ($order->items->sortByDesc('quantity') as $i) {
                if ($shortfall <= 0) break;
                $cut = min($shortfall, $suggestFinished[$i->id]);
                $suggestFinished[$i->id] -= $cut;
                $shortfall -= $cut;
            }
            $canFinish = $inProgress && $allStagesDone;
            $hasReceipts = $order->receipts->isNotEmpty();
        @endphp
        <x-card title="Rincian per Ukuran" :padding="false" class="lg:col-span-2" tip="Jumlah rencana per varian dan yang sudah disetor ke gudang. Saat menyelesaikan produksi, isi sisa jumlah jadi (bagus) dan grade B per ukuran; barang masuk stok dan HPP aktual SPK dihitung.">
            <form method="POST" action="{{ route('production-orders.complete', $order) }}" data-confirm="Sisa hasil dimasukkan ke stok dan SPK ditutup. Biaya yang tercatat sesudahnya tidak bisa ditambahkan lagi." data-confirm-icon="question" data-confirm-button="Ya, selesaikan">
                @csrf
                <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr>
                        <th>SKU</th><th>Ukuran / Warna</th><th class="text-right">Rencana</th>
                        <th class="text-right">{{ $canFinish && $hasReceipts ? 'Sudah Disetor' : 'Jadi' }}</th>
                        @if ($canFinish)
                            <th class="w-32 text-right">{{ $hasReceipts ? 'Sisa Jadi' : 'Jadi' }}</th>
                            <th class="w-32 text-right"><span class="inline-flex items-center gap-1">Grade B <x-tip title="Grade B" text="Reject yang masih punya nilai (bisa diperbaiki / dijual murah). Masuk stok Barang Cacat dengan HPP yang sama. Reject yang dibuang tidak perlu diisi; biayanya ditanggung barang bagus." /></span></th>
                        @else
                            <th class="text-right">Grade B</th>
                        @endif
                    </tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($order->items as $item)
                            <tr>
                                <td class="font-medium">{{ $item->variant->sku }}</td>
                                <td>{{ $item->variant->size }}{{ $item->variant->color ? ' / '.$item->variant->color : '' }}</td>
                                <td class="text-right">{{ qty($item->quantity) }}</td>
                                @if ($canFinish)
                                    <td class="text-right">{{ $hasReceipts ? qty((float) $item->finished_quantity + (float) $item->defect_quantity) : '' }}</td>
                                    <td class="text-right"><input type="number" min="0" max="{{ $item->remainingQuantity() }}" step="1" name="finished[{{ $item->id }}]" value="{{ old('finished.'.$item->id, $suggestFinished[$item->id]) }}" class="form-control text-right" aria-label="Jadi {{ $item->variant->sku }}"></td>
                                    <td class="text-right"><input type="number" min="0" max="{{ $item->remainingQuantity() }}" step="1" name="defects[{{ $item->id }}]" value="{{ old('defects.'.$item->id, 0) }}" class="form-control text-right" aria-label="Grade B {{ $item->variant->sku }}"></td>
                                @else
                                    <td class="text-right">{{ qty($item->finished_quantity) }}</td>
                                    <td class="text-right">{{ qty($item->defect_quantity) }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                </div>
                @if ($canFinish && $order->subcontractOrders->contains(fn ($s) => $s->isOpen()))
                    <p class="border-t border-gray-200 p-4 text-sm text-amber-700">Tutup order maklon yang masih berjalan (retur sisa bahan & rekonsiliasi) sebelum menyelesaikan produksi.</p>
                @elseif ($canFinish)
                    <div class="flex flex-wrap items-end justify-end gap-3 border-t border-gray-200 p-4">
                        @if ($lastStage && $lastOutput > 0)
                            <p class="mr-auto self-center text-sm text-gray-600">Output tahap {{ $lastStage->stage->label() }}: <span class="font-semibold">{{ qty($lastOutput) }} pcs</span>. Total jadi bagus (termasuk yang sudah disetor) tidak boleh melebihi angka ini.</p>
                        @endif
                        <div class="w-40"><x-form.input name="reject_quantity" type="number" min="0" step="1" label="Total Reject" tip="Total pcs gagal dari seluruh tahap, termasuk grade B. Dicatat di SPK untuk laporan produksi. Biaya reject yang dibuang ikut menjadi HPP barang yang bagus." :value="$order->stages->sum('reject_quantity')" /></div>
                        <button type="submit" class="btn-success">Selesaikan Produksi</button>
                    </div>
                @elseif ($inProgress)
                    <p class="border-t border-gray-200 p-4 text-sm text-gray-500">Selesaikan semua tahapan untuk menutup SPK. Hasil yang sudah jadi bisa masuk gudang lebih dulu lewat <strong>Setor Hasil</strong>.</p>
                @endif
            </form>
        </x-card>

        @if ($order->receipts->isNotEmpty() || $order->materialTransfers->isNotEmpty())
            <x-card title="Setoran Hasil & Perpindahan Bahan" :padding="false" class="lg:col-span-3" tip="Dokumen yang menggerakkan stok selama SPK berjalan: setor hasil ke gudang (HPJ), bahan tambahan (BBT), dan retur sisa bahan (RSB).">
                <div class="overflow-x-auto">
                    <table class="table-base">
                        <thead><tr><th>Dokumen</th><th>Tanggal</th><th>Jenis</th><th>Rincian</th>@can('costing.view')<th class="text-right">Nilai</th>@endcan<th class="w-16"></th></tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($order->receipts->map(fn ($r) => ['doc' => $r, 'date' => $r->date, 'kind' => 'receipt'])->concat($order->materialTransfers->map(fn ($t) => ['doc' => $t, 'date' => $t->date, 'kind' => 'transfer']))->sortBy(fn ($row) => [$row['date']->timestamp, $row['doc']->id]) as $row)
                                @php $doc = $row['doc']; @endphp
                                <tr>
                                    <td class="font-medium whitespace-nowrap">{{ $doc->number }}</td>
                                    <td class="whitespace-nowrap">{{ tanggal($doc->date) }}</td>
                                    @if ($row['kind'] === 'receipt')
                                        <td><x-badge color="green">{{ $doc->is_final ? 'Setoran Penutup' : 'Setor Hasil' }}</x-badge></td>
                                        <td>{{ qty($doc->quantity) }} pcs bagus{{ (float) $doc->defect_quantity > 0 ? ' + '.qty($doc->defect_quantity).' pcs grade B' : '' }}@can('costing.view')<span class="text-xs text-gray-500"> · {{ rupiah($doc->unit_cost) }}/pcs</span>@endcan</td>
                                        @can('costing.view')<td class="text-right whitespace-nowrap">{{ rupiah($doc->total_cost) }}</td>@endcan
                                        <td class="text-right"><a href="{{ route('production-receipts.pdf', $doc) }}" target="_blank" class="text-xs text-indigo-600 hover:underline">Cetak</a></td>
                                    @else
                                        <td><x-badge :color="$doc->type->color()">{{ $doc->type->label() }}</x-badge></td>
                                        <td>{{ $doc->items->count() }} bahan{{ $doc->notes ? ' · '.$doc->notes : '' }}</td>
                                        @can('costing.view')<td class="text-right whitespace-nowrap">{{ $doc->type === \App\Enums\ProductionMaterialTransferType::Return ? '−' : '' }}{{ rupiah($doc->totalValue()) }}</td>@endcan
                                        <td class="text-right"><a href="{{ route('production-material-transfers.pdf', $doc) }}" target="_blank" class="text-xs text-indigo-600 hover:underline">Cetak</a></td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </x-card>
        @endif

        @php $canSubcontract = in_array($order->status, [ProductionOrderStatus::Draft, ProductionOrderStatus::InProgress], true) && auth()->user()->can('subcontract.manage'); @endphp
        @if ($order->subcontractOrders->isNotEmpty() || $canSubcontract)
            <x-card title="Maklon (Vendor Luar)" :padding="false" class="lg:col-span-3">
                @if ($canSubcontract)
                    <x-slot:header>
                        <a href="{{ route('subcontract-orders.create', ['production_order_id' => $order->id]) }}" class="btn-secondary btn-sm">+ Order Maklon</a>
                    </x-slot:header>
                @endif
                <div class="overflow-x-auto">
                    <table class="table-base">
                        <thead><tr><th>No. Order</th><th>Vendor</th><th>Tahap</th><th class="text-right">Qty</th><th class="text-right">Bagus / Reject</th><th class="text-right">Ongkos Jasa</th><th>Status</th></tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($order->subcontractOrders as $sub)
                                <tr>
                                    <td><a href="{{ route('subcontract-orders.show', $sub) }}" class="font-medium text-indigo-600 hover:underline">{{ $sub->number }}</a></td>
                                    <td>{{ $sub->supplier->name }}</td>
                                    <td class="text-xs">{{ $sub->stagesLabel() }}</td>
                                    <td class="text-right">{{ qty($sub->quantity) }}</td>
                                    <td class="text-right whitespace-nowrap">{{ qty($sub->received_quantity) }} / {{ qty($sub->reject_quantity) }}</td>
                                    <td class="text-right whitespace-nowrap">{{ rupiah($sub->service_cost) }}</td>
                                    <td><x-status-badge :status="$sub->status" /></td>
                                </tr>
                            @empty
                                <x-empty-row compact colspan="7" message="Semua tahap dikerjakan internal. Buat order maklon bila sebagian / seluruh tahap dikerjakan vendor luar dengan bahan dari gudang." />
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </x-card>
        @endif

        @php
            $usesPiecework = $order->product->usesPiecework();
            $canPiecework = $order->status === ProductionOrderStatus::InProgress && auth()->user()->can('piecework.record');
        @endphp
        @if (($usesPiecework || $order->pieceworkSheets->isNotEmpty()) && auth()->user()->can('payroll.view'))
            <x-card title="Upah Borongan Karyawan" :padding="false" class="lg:col-span-3">
                @if ($canPiecework)
                    <x-slot:header>
                        <a href="{{ route('piecework.create', ['production_order_id' => $order->id]) }}" class="btn-secondary btn-sm">+ Input Hasil Kerja</a>
                    </x-slot:header>
                @endif
                <div class="grid grid-cols-1 lg:grid-cols-2">
                    <div class="overflow-x-auto border-b border-gray-200 lg:border-r lg:border-b-0">
                        <table class="table-base">
                            <thead><tr><th>Operasi</th><th class="text-right">Selesai</th><th class="text-right">Reject</th><th class="text-right">Sisa</th></tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($pieceworkProgress as $key => $row)
                                    @continue($key === 'full' && $row['rate'] <= 0 && $row['used'] <= 0)
                                    <tr>
                                        <td>{{ $row['name'] }}@if ($row['stage'])<span class="text-xs text-gray-500"> · {{ $row['stage'] }}</span>@endif</td>
                                        <td class="text-right">{{ qty($row['good']) }} <span class="text-xs text-gray-500">/ {{ qty($row['capacity']) }}</span></td>
                                        <td class="text-right">{{ qty($row['reject']) }}</td>
                                        @if ($key === 'full' && $row['remaining'] <= 0 && $row['used'] < $row['capacity'])
                                            {{-- Pcs jadi butuh semua operasi; bila sebagian operasi sudah dikerjakan per operasi, sisanya tidak bisa lagi dicatat sebagai pcs jadi --}}
                                            <td class="text-right text-gray-400"><x-tip title="Tidak tersedia" text="Pcs jadi (komplit) hanya bisa dicatat selama SEMUA operasi masih tersisa. Sebagian operasi sudah dicatat per operasi, jadi lanjutkan pencatatan per operasi." />–</td>
                                        @else
                                            <td @class(['text-right font-medium', 'text-emerald-600' => $row['remaining'] <= 0])>{{ qty($row['remaining']) }}</td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="table-base">
                            <thead><tr><th>Lembar</th><th>Tanggal</th><th class="text-right">Karyawan</th><th class="text-right">Upah</th></tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                @forelse ($order->pieceworkSheets as $sheet)
                                    <tr>
                                        <td><a href="{{ route('piecework.show', $sheet) }}" class="font-medium text-indigo-600 hover:underline">{{ $sheet->number }}</a></td>
                                        <td class="whitespace-nowrap">{{ tanggal($sheet->date) }}</td>
                                        <td class="text-right">{{ $sheet->entries->pluck('employee_id')->unique()->count() }}</td>
                                        <td class="text-right whitespace-nowrap">{{ rupiah($sheet->total_amount) }}</td>
                                    </tr>
                                @empty
                                    <x-empty-row compact colspan="4" message="Belum ada hasil kerja harian." />
                                @endforelse
                            </tbody>
                            @if ($order->pieceworkSheets->isNotEmpty())
                                <tfoot class="bg-gray-50 font-semibold"><tr><td colspan="3" class="px-4 py-3">Total upah borongan (masuk HPP)</td><td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($order->pieceworkSheets->sum('total_amount')) }}</td></tr></tfoot>
                            @endif
                        </table>
                    </div>
                </div>
            </x-card>
        @endif

        <x-card title="Tahapan Produksi" class="lg:col-span-3" tip="Catat progres tiap tahap (output bagus & reject). Semua tahap harus Selesai sebelum produksi bisa diselesaikan. Tahap yang dimaklonkan diperbarui otomatis dari penerimaan hasil maklon.">
            <ol @class(["grid grid-cols-1 gap-4 md:grid-cols-2", "xl:grid-cols-5" => $order->stages->count() <= 5, "xl:grid-cols-4" => $order->stages->count() > 5])>
                @foreach ($order->stages as $stage)
                    <li class="rounded-lg border border-gray-200 p-4" x-data="{ edit: false }">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <p class="text-xs text-gray-500">Tahap {{ $stage->sequence }}</p>
                                <p class="font-semibold text-gray-800">{{ $stage->stage->label() }}</p>
                            </div>
                            <x-badge :color="$stage->status->color()">{{ $stage->status->label() }}</x-badge>
                        </div>
                        @if ($stage->subcontractOrder && $stage->subcontractOrder->status !== \App\Enums\SubcontractStatus::Cancelled)
                            <a href="{{ route('subcontract-orders.show', $stage->subcontractOrder) }}" class="mt-2 inline-flex"><x-badge color="indigo">Maklon · {{ $stage->subcontractOrder->supplier->name }}</x-badge></a>
                        @endif
                        @php
                            // Progres dari hasil kerja borongan untuk tahap ini (pcs yang sudah melewati semua operasi tahap ini)
                            $stageOps = collect($pieceworkProgress ?? [])->except('full')->where('stage', $stage->stage->label());
                            $stagePiecework = $stageOps->isNotEmpty() ? (float) $stageOps->min('good') : null;
                        @endphp
                        <dl class="mt-3 space-y-1 text-xs text-gray-600">
                            @if ($stagePiecework !== null && ! $stage->subcontractOrder && $order->status === ProductionOrderStatus::InProgress)
                                <div class="flex justify-between"><dt class="flex items-center gap-1">Hasil borongan <x-tip text="Pcs yang sudah dikerjakan karyawan di semua operasi tahap ini menurut lembar hasil kerja harian. Pakai angka ini sebagai acuan saat mengisi Output tahap." /></dt><dd class="font-medium">{{ qty($stagePiecework) }} / {{ qty($order->quantity) }}</dd></div>
                            @endif
                            <div class="flex justify-between"><dt>Output</dt><dd class="font-medium">{{ qty($stage->output_quantity) }}</dd></div>
                            <div class="flex justify-between"><dt>Reject</dt><dd class="font-medium">{{ qty($stage->reject_quantity) }}</dd></div>
                            <div class="flex justify-between"><dt>PIC</dt><dd>{{ $stage->pic ?: '-' }}</dd></div>
                            @if ($stage->finished_at)<div class="flex justify-between"><dt>Selesai</dt><dd>{{ tanggal($stage->finished_at, 'd M H:i') }}</dd></div>@endif
                        </dl>
                        @if ($inProgress && ! ($stage->subcontractOrder?->isOpen()))
                            <button type="button" class="mt-3 text-xs font-medium text-indigo-600 hover:underline" @click="edit = !edit" x-text="edit ? 'Tutup' : 'Perbarui'"></button>
                            <form x-cloak x-show="edit" method="POST" action="{{ route('production-orders.stages.update', [$order, $stage]) }}" class="mt-3 space-y-2">
                                @csrf
                                @method('PUT')
                                <x-form.label label="Status" tip="Menunggu → Dikerjakan → Selesai. Tahap berikutnya sebaiknya dimulai setelah tahap sebelumnya selesai." />
                                <select name="status" class="form-control py-1.5">
                                    @foreach ($stageStatuses as $status)
                                        <option value="{{ $status->value }}" @selected($stage->status === $status)>{{ $status->label() }}</option>
                                    @endforeach
                                </select>
                                <x-form.label label="Output (pcs)" tip="Jumlah pcs yang lolos dari tahap ini. Output tahap terakhir (Packing) menjadi batas jumlah barang jadi." />
                                <input type="number" min="0" step="1" name="output_quantity" value="{{ (float) ($stage->output_quantity ?: ($stagePiecework ?: $order->quantity)) }}" class="form-control py-1.5" placeholder="Output" aria-label="Output">
                                <x-form.label label="Reject (pcs)" tip="Jumlah pcs gagal/cacat di tahap ini." />
                                <input type="number" min="0" step="1" name="reject_quantity" value="{{ (float) $stage->reject_quantity }}" class="form-control py-1.5" placeholder="Reject">
                                <input type="text" name="pic" value="{{ $stage->pic }}" class="form-control py-1.5" placeholder="PIC / Operator">
                                <textarea name="notes" rows="2" class="form-control py-1.5" placeholder="Catatan">{{ $stage->notes }}</textarea>
                                <button type="submit" class="btn-primary btn-sm w-full">Simpan</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ol>
        </x-card>

        <x-card title="Kebutuhan & Pemakaian Bahan" :padding="false" class="lg:col-span-3">
            <table class="table-base">
                <thead><tr><th>Bahan</th><th class="text-right">Kebutuhan</th><th class="text-right">Dikeluarkan</th><th class="text-right">Diretur</th><th class="text-right">Stok Gudang</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($order->materials as $line)
                        <tr>
                            <td>{{ $line->material->display_name }}</td>
                            <td class="text-right">{{ qty($line->required_quantity) }} {{ $line->material->unit->code }}</td>
                            <td class="text-right">{{ qty($line->issued_quantity) }}</td>
                            <td class="text-right">{{ qty($line->returned_quantity) }}</td>
                            <td @class(['text-right', 'text-red-600 font-semibold' => $order->isEditable() && $line->material->stock < $line->required_quantity])>{{ qty($line->material->stock) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-card>

        @can('costing.view')
            @php
                $cost = $order->costSummary();
                $canCost = $order->canRecordCosts() && auth()->user()->can('production.manage');
                $isCompleted = $order->status === ProductionOrderStatus::Completed;
            @endphp
            <x-card title="Biaya Produksi (HPP)" :padding="false" class="lg:col-span-3">
                <x-slot:header>
                    <span class="text-xs text-gray-500">{{ $isCompleted ? 'HPP aktual dibebankan ke '.qty($cost['basis_quantity']).' pcs barang jadi' : 'Proyeksi untuk '.qty($cost['basis_quantity']).' pcs rencana' }}</span>
                </x-slot:header>
                <div class="grid grid-cols-2 gap-4 border-b border-gray-200 p-5 md:grid-cols-4">
                    <div>
                        <p class="text-xs text-gray-500">Biaya bahan {{ $order->materials_issued_at ? '(aktual)' : '(estimasi)' }}</p>
                        <p class="text-lg font-semibold">{{ rupiah($cost['material']) }}</p>
                        <p class="text-xs text-gray-500">Standar {{ rupiah($cost['standard_material']) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Upah / CMT / overhead</p>
                        <p class="text-lg font-semibold">{{ rupiah($cost['conversion']) }}</p>
                        <p class="text-xs text-gray-500">Standar {{ rupiah($cost['standard_conversion']) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">Total biaya</p>
                        <p class="text-lg font-semibold">{{ rupiah($cost['total']) }}</p>
                        <p class="text-xs text-gray-500">Standar {{ rupiah($cost['standard_total']) }}</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500">HPP per pcs</p>
                        <p class="text-lg font-semibold text-indigo-700">{{ rupiah($cost['unit']) }}</p>
                        @php $variance = $cost['unit'] - $cost['standard_unit']; @endphp
                        <p @class(['text-xs', 'text-red-600' => $variance > 0.5, 'text-emerald-600' => $variance < -0.5, 'text-gray-500' => abs($variance) <= 0.5])>
                            Standar {{ rupiah($cost['standard_unit']) }}@if (abs($variance) > 0.5) ({{ $variance > 0 ? '+' : '−' }}{{ rupiah(abs($variance), false) }})@endif
                        </p>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="table-base">
                        <thead><tr><th>Tanggal</th><th>Jenis</th><th>Keterangan</th><th class="text-right">Jumlah</th>@if ($canCost)<th class="w-16"></th>@endif</tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($order->costs as $line)
                                <tr>
                                    <td class="whitespace-nowrap">{{ tanggal($line->date) }}</td>
                                    <td><x-badge :color="$line->type->color()">{{ $line->type->label() }}</x-badge></td>
                                    <td>{{ $line->description }}</td>
                                    <td class="text-right whitespace-nowrap">{{ rupiah($line->amount) }}</td>
                                    @if ($canCost)
                                        <td class="text-right">
                                            @if ($line->piecework_sheet_id)
                                                <a href="{{ route('piecework.show', $line->piecework_sheet_id) }}" class="text-xs text-gray-400 hover:text-indigo-600" title="Otomatis dari hasil kerja borongan">Borongan</a>
                                            @elseif ($line->subcontract_receipt_id)
                                                <span class="text-xs text-gray-400" title="Otomatis dari penerimaan hasil maklon">Maklon</span>
                                            @else
                                                <x-delete-button :action="route('production-orders.costs.destroy', [$order, $line])" confirm="Biaya ini akan dihapus dari SPK." />
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @empty
                                <x-empty-row compact :colspan="$canCost ? 5 : 4" :message="$order->isEditable() ? ($order->product->usesPiecework() ? 'Overhead standar BOM dibebankan saat produksi dimulai; upah masuk dari hasil kerja borongan harian.' : 'Upah & overhead standar BOM dibebankan otomatis saat produksi dimulai. Biaya CMT/maklon dapat dicatat di sini.') : 'Belum ada biaya konversi.'" />
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($canCost)
                    <form method="POST" action="{{ route('production-orders.costs.store', $order) }}" class="grid grid-cols-1 gap-3 border-t border-gray-200 p-4 sm:grid-cols-2 lg:grid-cols-6 lg:items-end">
                        @csrf
                        <x-form.input name="date" label="Tanggal" :value="now()->toDateString()" required data-date class="lg:col-span-1" />
                        <x-form.select name="type" label="Jenis Biaya" tip="Jasa CMT/maklon: ongkos jahit di luar. Upah tenaga kerja: upah karyawan. Overhead pabrik: listrik, sewa, dll. Biaya lain: selain itu. Semua menambah HPP SPK." :options="$costTypes" :value="old('type', 'cmt')" :placeholder="false" required class="lg:col-span-1" />
                        <x-form.input name="description" label="Keterangan" required placeholder="mis. Ongkos jahit CMT Bu Ani" class="lg:col-span-2" />
                        <x-form.input name="amount" label="Jumlah (Rp)" required data-money inputmode="numeric" class="lg:col-span-1" />
                        <button type="submit" class="btn-primary lg:col-span-1">+ Catat Biaya</button>
                    </form>
                @endif
            </x-card>
        @endcan

        <x-journal-entries :sources="collect([$order])->concat($order->receipts)->concat($order->materialTransfers)->all()" class="lg:col-span-3" />
        <x-activity-log :activities="$order->activities" class="lg:col-span-3" />
    </div>
</x-layouts.app>
