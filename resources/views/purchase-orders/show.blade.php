@php
    use App\Enums\PurchaseOrderStatus;
    $needsApproval = $order->requiresApproval();
    $canDecide = $order->isPendingApproval() && auth()->user()->can('purchase.approve')
        && ($order->created_by !== auth()->id() || auth()->user()->isAdmin());
@endphp
<x-layouts.app :title="$order->number" :subtitle="'Purchase Order · '.$order->supplier->name">
    <x-slot:actions>
        <x-more-actions>
            <a href="{{ route('purchase-orders.pdf', $order) }}" target="_blank" class="menu-item">Cetak PO</a>
            @can('purchase.manage')
                @if ($order->isEditable())
                    <a href="{{ route('purchase-orders.edit', $order) }}" class="menu-item">Ubah PO</a>
                @endif
                @if ($order->canClose())
                    <x-reason-form :action="route('purchase-orders.close', $order)" title="Tutup PO?" text="Sisa qty yang belum diterima tidak lagi ditunggu dari supplier dan keluar dari hitungan MRP." placeholder="Alasan, mis. supplier kehabisan stok" button="Ya, tutup PO" :danger="false" class="menu-item">Tutup Sisa PO</x-reason-form>
                @endif
                @if ($order->canCancel() || $order->isEditable())
                    <div class="menu-divider"></div>
                @endif
                @if ($order->canCancel())
                    <x-action-form :action="route('purchase-orders.cancel', $order)" confirm="PO akan dibatalkan." button="Ya, batalkan" class="menu-item-danger">Batalkan PO</x-action-form>
                @endif
                @if ($order->isEditable())
                    <x-delete-button :action="route('purchase-orders.destroy', $order)" label="Hapus PO" class="menu-item-danger" />
                @endif
            @endcan
        </x-more-actions>
        @if ($order->canReturn() && auth()->user()->can('purchase-returns.manage'))
            <a href="{{ route('purchase-returns.create', ['order_id' => $order->id]) }}" class="btn-secondary">Retur ke Supplier</a>
        @endif
        @can('purchase.manage')
            @if ($order->isEditable())
                @if ($needsApproval)
                    <x-action-form :action="route('purchase-orders.submit', $order)" confirm="Nilai PO melewati batas persetujuan. PO akan dikunci dan diajukan ke penyetuju." icon="question" button="Ya, ajukan" class="btn-primary">Ajukan Persetujuan</x-action-form>
                @else
                    <x-action-form :action="route('purchase-orders.submit', $order)" confirm="PO akan dikunci dan ditandai sudah dipesan ke supplier." icon="question" button="Ya, pesan" class="btn-primary">Tandai Dipesan</x-action-form>
                @endif
            @endif
        @endcan
        @if ($order->canReceive() && auth()->user()->can('purchase.receive'))
            <a href="{{ route('purchase-orders.receive', $order) }}" class="btn-primary">Terima Barang</a>
        @endif
        @if ($order->canBill() && auth()->user()->can('payables.manage'))
            <a href="{{ route('bills.create', ['purchase_order_id' => $order->id]) }}" class="btn-primary">Catat Tagihan</a>
        @endif
    </x-slot:actions>

    {{-- Petunjuk langkah berikutnya sesuai status PO --}}
    @if ($order->isEditable())
        <x-next-step>@if ($needsApproval) Nilai PO melewati batas persetujuan. Klik <strong>Ajukan Persetujuan</strong>; setelah disetujui, PO baru boleh dikirim ke supplier. @else Kirim PO ke supplier (menu Lainnya → Cetak PO), lalu klik <strong>Tandai Dipesan</strong> agar PO terkunci dan barangnya ditunggu. @endif</x-next-step>
    @elseif ($order->canReceive())
        <x-next-step>Saat barang datang, klik <strong>Terima Barang</strong> dan isi qty yang benar-benar diterima (boleh bertahap). Stok bahan bertambah otomatis.@if ($order->canBill()) Barang yang sudah diterima bisa dicatat tagihannya lewat <strong>Catat Tagihan</strong>.@endif @if ($order->canClose()) Bila sisa barang tidak akan dikirim supplier, tutup lewat <strong>Lainnya → Tutup Sisa PO</strong>.@endif</x-next-step>
    @elseif ($order->canBill())
        <x-next-step>Semua barang sudah diterima. Saat faktur supplier datang, klik <strong>Catat Tagihan</strong>.</x-next-step>
    @elseif (in_array($order->status, [PurchaseOrderStatus::Received, PurchaseOrderStatus::Closed], true))
        <x-next-step tone="green" title="PO selesai">Barang sudah diterima dan ditagih. Pembayarannya dipantau di menu Tagihan Supplier.</x-next-step>
    @endif

    @if ($order->isPendingApproval())
        <div class="mb-6 rounded-xl border border-indigo-200 bg-indigo-50 p-4">
            <p class="text-sm font-semibold text-indigo-900">Menunggu persetujuan</p>
            <p class="mt-1 text-sm text-indigo-800">
                Nilai PO {{ rupiah($order->total) }} mencapai batas persetujuan {{ rupiah(\App\Models\Setting::poApprovalThreshold()) }}.
                Diajukan {{ $order->submitted_at?->translatedFormat('d M Y H:i') ?? '-' }} oleh {{ $order->creator?->name ?? '-' }}.
                PO belum boleh dikirim ke supplier sebelum disetujui.
            </p>
            @if ($canDecide)
                <form method="POST" action="{{ route('purchase-orders.approve', $order) }}" class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-start">
                    @csrf
                    <div class="flex-1">
                        <textarea name="approval_notes" rows="2" class="form-control" placeholder="Catatan persetujuan (opsional) / alasan penolakan (wajib bila menolak)">{{ old('approval_notes') }}</textarea>
                        @error('approval_notes')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="flex gap-2">
                        <button type="submit" class="btn-success">Setujui</button>
                        <button type="submit" formaction="{{ route('purchase-orders.reject', $order) }}" class="btn-outline-danger">Tolak</button>
                    </div>
                </form>
            @elseif (auth()->user()->can('purchase.approve'))
                <p class="mt-2 text-xs text-indigo-700">PO ini Anda buat sendiri, jadi harus disetujui pengguna lain yang berwenang.</p>
            @endif
        </div>
    @elseif ($order->isEditable() && $order->approval_notes)
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
            <p class="font-semibold">PO ditolak penyetuju</p>
            <p class="mt-1">{{ $order->approval_notes }}</p>
            <p class="mt-1 text-xs text-red-700">Revisi PO lalu ajukan ulang.</p>
        </div>
    @elseif ($order->isEditable() && $needsApproval)
        <div class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            Nilai PO mencapai batas persetujuan {{ rupiah(\App\Models\Setting::poApprovalThreshold()) }}, jadi perlu disetujui sebelum dipesan ke supplier.
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Informasi">
            <dl class="space-y-3 text-sm">
                <div><dt class="text-gray-500">Status</dt><dd><x-status-badge :status="$order->status" /></dd></div>
                <div><dt class="text-gray-500">Supplier</dt><dd class="font-medium">{{ $order->supplier->name }}</dd><dd class="text-gray-500">{{ $order->supplier->phone }}</dd></div>
                <div><dt class="text-gray-500">Tanggal PO</dt><dd>{{ tanggal($order->order_date) }}</dd></div>
                <div><dt class="text-gray-500">Estimasi Datang</dt><dd>{{ tanggal($order->expected_date) }}</dd></div>
                <div><dt class="text-gray-500">Dibuat oleh</dt><dd>{{ $order->creator?->name ?? '-' }}</dd></div>
                @if ($order->approver)
                    <div>
                        <dt class="text-gray-500">Disetujui oleh</dt>
                        <dd>{{ $order->approver->name }} <span class="text-gray-500">· {{ $order->approved_at->translatedFormat('d M Y H:i') }}</span></dd>
                        @if ($order->approval_notes)<dd class="text-gray-500">{{ $order->approval_notes }}</dd>@endif
                    </div>
                @endif
                @if ($order->notes)<div><dt class="text-gray-500">Catatan</dt><dd>{{ $order->notes }}</dd></div>@endif
            </dl>
        </x-card>

        <x-card title="Item" :padding="false" class="lg:col-span-2">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>Bahan</th><th class="text-right">Qty</th><th class="text-right">Diterima</th><th class="text-right">Ditagih</th><th class="text-right">Retur</th><th class="text-right">Harga</th><th class="text-right">Subtotal</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($order->items as $item)
                            <tr>
                                <td>
                                    {{ $item->material->display_name }}
                                    @if ($item->isConverted())<div class="text-xs text-gray-500">{{ $item->conversionLabel() }}</div>@endif
                                </td>
                                <td class="text-right">{{ qty($item->quantity) }} {{ $item->unitCode() }}</td>
                                <td @class(['text-right', 'text-emerald-600 font-medium' => $item->outstandingQuantity() <= 0])>{{ qty($item->received_quantity) }}</td>
                                <td @class(['text-right', 'text-amber-600' => $item->unbilledQuantity() > 0])>{{ qty($item->billed_quantity) }}</td>
                                <td @class(['text-right', 'text-red-600' => (float) $item->returned_quantity > 0])>{{ qty($item->returned_quantity) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($item->price) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($item->subtotal) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot><tr class="bg-gray-50 font-semibold"><td colspan="6" class="px-4 py-3 text-right">Total</td><td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($order->total) }}</td></tr></tfoot>
                </table>
            </div>
        </x-card>

        <x-card title="Riwayat Penerimaan Barang" :padding="false" class="lg:col-span-3">
            <table class="table-base">
                <thead><tr><th>No. Penerimaan</th><th>Tanggal</th><th>Surat Jalan</th><th>Item</th><th></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($order->receipts as $receipt)
                        <tr>
                            <td class="font-medium">{{ $receipt->number }}</td>
                            <td>{{ tanggal($receipt->receipt_date) }}<x-warehouse-tag :warehouse="$receipt->warehouse" /></td>
                            <td>{{ $receipt->delivery_note ?: '-' }}</td>
                            <td>{!! $receipt->items->map(fn ($i) => e($i->material->name).': <b>'.qty($i->quantity).' '.e($i->purchaseOrderItem?->unitCode()).'</b>'.($i->purchaseOrderItem?->isConverted() ? ' <span class="text-gray-500">('.qty($i->stock_quantity).' '.e($i->material->unit?->code).')</span>' : ''))->implode('<br>') !!}</td>
                            <td class="text-right"><a href="{{ route('goods-receipts.pdf', $receipt) }}" target="_blank" class="text-sm font-medium text-indigo-600 hover:underline">Cetak</a></td>
                        </tr>
                    @empty
                        <x-empty-row compact colspan="5" message="Belum ada penerimaan barang." />
                    @endforelse
                </tbody>
            </table>
        </x-card>

        @if ($order->returns->isNotEmpty())
            @include('returns.partials.order-returns', ['kind' => \App\Support\ReturnKind::purchases(), 'returns' => $order->returns])
        @endif

        @can('payables.view')
        <x-card title="Tagihan Supplier" :padding="false" class="lg:col-span-3">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>No. Tagihan</th><th>Faktur Supplier</th><th>Tanggal</th><th>Jatuh Tempo</th><th class="text-right">Total</th><th class="text-right">Sisa</th><th>Status</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($order->bills as $bill)
                            <tr>
                                <td><a href="{{ route('bills.show', $bill) }}" class="font-medium text-indigo-600 hover:underline">{{ $bill->number }}</a></td>
                                <td>{{ $bill->supplier_invoice_number ?: '-' }}</td>
                                <td>{{ tanggal($bill->bill_date) }}</td>
                                <td>{{ tanggal($bill->due_date) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($bill->total) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($bill->outstanding()) }}</td>
                                <td><x-payable-status :doc="$bill" /></td>
                            </tr>
                        @empty
                            <x-empty-row compact colspan="7" message="Belum ada tagihan supplier untuk PO ini." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>
        @endcan

        <x-journal-entries :sources="$order->receipts->all()" class="lg:col-span-3" />
        <x-activity-log :activities="$order->activities" class="lg:col-span-3" />
    </div>
</x-layouts.app>
