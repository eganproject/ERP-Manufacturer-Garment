@use('App\Enums\MarketplaceOrderStatus')
@use('App\Enums\MarketplaceReturnCondition')
@use('App\Enums\MarketplaceClaimStatus')
@php
    $canReturn = in_array($order->status, [MarketplaceOrderStatus::ReturnRequested, MarketplaceOrderStatus::Shipped, MarketplaceOrderStatus::Completed], true) && ! $order->returned_at;
@endphp
<x-layouts.app :title="'Pesanan '.$order->order_number">
    <x-slot:actions>
        @can('marketplace.manage')
            @if ($order->status === MarketplaceOrderStatus::Pending || $order->status === MarketplaceOrderStatus::ToShip)
                <form method="POST" action="{{ route('marketplace-orders.ship', $order) }}" class="inline-flex items-center gap-2" data-confirm="Stok keluar dari {{ $order->store->warehouse->name }} dan penjualan dicatat." data-confirm-button="Ya, kirim">
                    @csrf
                    <input type="text" name="date" value="{{ today()->toDateString() }}" data-date class="form-control w-36">
                    <button type="submit" class="btn-primary">Kirim Pesanan</button>
                </form>
                <x-reason-form :action="route('marketplace-orders.cancel', $order)" title="Batalkan pesanan?" text="Pesanan belum dikirim, tidak ada stok/jurnal yang berubah." button="Ya, batalkan" class="btn-outline-danger">Batalkan</x-reason-form>
            @endif
            @if ($order->status === MarketplaceOrderStatus::Shipped)
                <x-action-form :action="route('marketplace-orders.complete', $order)">Tandai Selesai</x-action-form>
            @endif
            @if (in_array($order->status, [MarketplaceOrderStatus::Shipped, MarketplaceOrderStatus::Completed], true))
                <x-reason-form :action="route('marketplace-orders.request-return', $order)" title="Proses retur?" text="Pembeli mengajukan pengembalian / paket gagal kirim. Stok baru bertambah setelah barang diterima." placeholder="Alasan retur" button="Proses retur" :danger="false">Ajukan Retur</x-reason-form>
            @endif
            @if ($order->status === MarketplaceOrderStatus::ReturnRequested)
                <x-reason-form :action="route('marketplace-orders.reject-return', $order)" title="Batalkan retur?" text="Pengajuan retur ditolak / dibatalkan; pesanan kembali selesai." button="Ya, batalkan retur" class="btn-secondary">Retur Batal</x-reason-form>
            @endif
        @endcan
    </x-slot:actions>

    @php
        $state = $fulfillment['state'] ?? null;
        $variantNames = $order->items->pluck('variant', 'product_variant_id')->filter();
    @endphp
    @if ($order->status === MarketplaceOrderStatus::Pending)
        <x-next-step tone="amber">Pembeli belum membayar. Pesanan belum dihitung di Rencana Produksi; impor ulang file pesanan setelah dibayar.</x-next-step>
    @elseif ($state === \App\Services\Marketplace\MarketplaceFulfillment::READY)
        <x-next-step tone="green">Stok sudah dialokasikan untuk pesanan ini. Kemas & kirim (cetak <strong>Daftar Ambil Barang</strong> dari daftar pesanan), lalu klik <strong>Kirim Pesanan</strong> atau impor ulang file berstatus dikirim.</x-next-step>
    @elseif ($state === \App\Services\Marketplace\MarketplaceFulfillment::WAITING)
        <x-next-step tone="amber">Stok gudang toko belum cukup. Pesanan ini ikut dihitung di <strong>Rencana Produksi</strong>; buat SPK dari sana. Saat hasil SPK masuk gudang, pesanan otomatis menjadi Siap Dikirim.</x-next-step>
    @elseif ($state === \App\Services\Marketplace\MarketplaceFulfillment::UNMAPPED)
        <x-next-step tone="amber">Ada SKU yang belum dipetakan ke varian ERP. Petakan di <strong>Mapping SKU</strong> agar stok bisa dialokasikan dan pesanan dihitung di rencana produksi.</x-next-step>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Informasi">
            <dl class="space-y-3 text-sm">
                <div><dt class="text-gray-500">Status</dt><dd><x-status-badge :status="$order->status" /> <span class="text-xs text-gray-500">{{ $order->platform_status }}</span>
                    @if ($order->cancel_reason)<div class="mt-1 text-gray-600">Batal: {{ $order->cancel_reason }}</div>@endif
                    @if ($order->return_reason)<div class="mt-1 text-gray-600">Retur: {{ $order->return_reason }}</div>@endif</dd></div>
                @if (! $order->isShipped() && $order->status !== MarketplaceOrderStatus::Cancelled)
                    <div x-data="{ edit: false }"><dt class="text-gray-500">Jenis & batas kirim</dt>
                        <dd class="flex flex-wrap items-center gap-2">
                            <x-badge :color="$order->is_preorder ? 'indigo' : 'gray'">{{ $order->is_preorder ? 'PO (pre-order)' : 'Ready stock' }}</x-badge>
                            @if ($order->ship_by)
                                <span @class(['font-medium', 'text-red-600' => $order->daysToShipBy() !== null && $order->daysToShipBy() <= \App\Services\Marketplace\MarketplaceFulfillment::URGENT_DAYS])>{{ tanggal($order->ship_by) }}</span>
                                <span class="text-xs text-gray-500">({{ $order->daysToShipBy() < 0 ? 'terlambat '.abs($order->daysToShipBy()).' hari' : $order->daysToShipBy().' hari lagi' }})</span>
                            @endif
                            @can('marketplace.manage')<button type="button" class="text-xs text-indigo-600 hover:underline" @click="edit = ! edit">Ubah</button>@endcan
                        </dd>
                        @if ($state === \App\Services\Marketplace\MarketplaceFulfillment::READY)
                            <dd class="mt-1"><x-badge color="green">Siap dikirim</x-badge></dd>
                        @elseif ($state === \App\Services\Marketplace\MarketplaceFulfillment::WAITING)
                            <dd class="mt-1"><x-badge color="amber">Menunggu produksi</x-badge>
                                <div class="mt-1 text-xs text-gray-600">Kurang: {{ collect($fulfillment['short'])->map(fn ($q, $id) => ($variantNames[$id]?->sku ?? '#'.$id).' '.qty($q))->implode(', ') }}</div></dd>
                        @endif
                        @can('marketplace.manage')
                            <form x-cloak x-show="edit" method="POST" action="{{ route('marketplace-orders.schedule', $order) }}" class="mt-2 space-y-2 rounded-lg border border-gray-200 p-3">
                                @csrf
                                <x-form.checkbox name="is_preorder" label="Pesanan PO (pre-order)" :checked="$order->is_preorder" tip="Centang bila pesanan ini pre-order. Hanya sebagai tanda; urutan alokasi stok mengikuti batas kirim." />
                                <x-form.input name="ship_by" label="Batas kirim" :value="$order->ship_by_fixed ? $order->ship_by : null" data-date tip="Tanggal pesanan harus dikirim (lihat Seller Center). Kosongkan untuk dihitung otomatis: tgl bayar + hari PO SKU / hari proses toko." />
                                <button type="submit" class="btn-primary btn-sm">Simpan</button>
                            </form>
                        @endcan
                    </div>
                @endif
                <div><dt class="text-gray-500">Toko</dt><dd class="font-medium">{{ $order->store->display_name }}</dd><dd class="text-xs text-gray-500">Gudang {{ $order->store->warehouse->name }}</dd></div>
                <div><dt class="text-gray-500">Tanggal pesanan</dt><dd>{{ $order->order_date->translatedFormat('d M Y H:i') }}</dd></div>
                @if ($order->paid_at)<div><dt class="text-gray-500">Dibayar</dt><dd>{{ $order->paid_at->translatedFormat('d M Y H:i') }}</dd></div>@endif
                <div><dt class="text-gray-500">Dikirim / Selesai</dt><dd>{{ $order->shipped_at ? tanggal($order->shipped_at) : '-' }} / {{ $order->completed_at ? tanggal($order->completed_at) : '-' }}</dd></div>
                @if ($order->returned_at)
                    <div><dt class="text-gray-500">Retur diterima</dt><dd>{{ tanggal($order->returned_at) }} · <x-badge :color="$order->return_condition?->color() ?? 'gray'">{{ $order->return_condition?->label() ?? '-' }}</x-badge></dd>
                        @if ((float) $order->returned_lost_cost)<dd class="text-xs text-red-600">Kerugian barang tidak kembali (HPP) {{ rupiah($order->returned_lost_cost) }}</dd>@endif</div>
                @endif
                @if ($order->claim_status)
                    <div><dt class="text-gray-500">Banding</dt><dd><x-status-badge :status="$order->claim_status" />@if ($order->claim_status === MarketplaceClaimStatus::Won) {{ rupiah($order->claim_amount) }}@endif
                        @if ($order->claim_resolved_at)<span class="text-xs text-gray-500">· {{ tanggal($order->claim_resolved_at) }}</span>@endif</dd>
                        @if ($order->claim_notes)<dd class="mt-1 text-xs whitespace-pre-line text-gray-600">{{ $order->claim_notes }}</dd>@endif</div>
                @endif
                @if ($order->isShipped())
                    <div><dt class="text-gray-500">Piutang marketplace</dt><dd @class(['text-red-600' => $order->outstandingReceivable() < 0])>{{ rupiah($order->outstandingReceivable()) }}
                        @if ($order->outstandingReceivable() < 0)<div class="text-xs">Kelebihan cair (retur setelah dana cair) — akan dipotong platform di pencairan berikutnya.</div>@endif</dd></div>
                @endif
                <div><dt class="text-gray-500">Pembeli</dt><dd>{{ $order->buyer_username ?? '-' }}@if ($order->recipient_name) · {{ $order->recipient_name }}@endif @if ($order->city)<div class="text-xs text-gray-500">{{ $order->city }}</div>@endif</dd></div>
                <div><dt class="text-gray-500">Pengiriman</dt><dd>{{ $order->courier ?? '-' }} {{ $order->tracking_number }}</dd>@if ((float) $order->buyer_shipping_fee)<dd class="text-xs text-gray-500">Ongkir dibayar pembeli {{ rupiah($order->buyer_shipping_fee) }}</dd>@endif</div>
                <div><dt class="text-gray-500">Sumber</dt><dd>{{ ['import' => 'Impor file', 'api' => 'API', 'manual' => 'Manual'][$order->source] ?? $order->source }}</dd></div>
            </dl>
        </x-card>

        <div class="space-y-6 lg:col-span-2">
            <x-card title="Barang" :padding="false">
                <div class="overflow-x-auto">
                    <table class="table-base">
                        <thead><tr><th>Produk Marketplace</th><th>Varian ERP</th><th class="text-right">Qty</th><th class="text-right">Harga</th><th class="text-right">Subtotal</th>@can('costing.view')<th class="text-right">HPP</th>@endcan @if ($order->returned_at)<th class="text-right">Retur</th>@endif</tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($order->items as $item)
                                <tr>
                                    <td>{{ $item->product_name ?? '-' }}<div class="text-xs text-gray-500">{{ $item->marketplace_sku ?: 'tanpa SKU' }}{{ $item->variation ? ' · '.$item->variation : '' }}</div></td>
                                    <td>
                                        @if ($item->variant){{ $item->variant->sku }}<div class="text-xs text-gray-500">{{ $item->variant->display_name }}{{ (float) $item->units_per_item != 1 ? ' · isi '.qty($item->units_per_item) : '' }}</div>
                                        @else<a href="{{ route('marketplace-mappings.index', ['store_id' => $order->marketplace_store_id]) }}" class="text-sm text-red-600 hover:underline">Belum dipetakan</a>@endif
                                    </td>
                                    <td class="text-right">{{ qty($item->quantity) }}</td>
                                    <td class="text-right whitespace-nowrap">{{ rupiah($item->unit_price) }}</td>
                                    <td class="text-right whitespace-nowrap">{{ rupiah($item->subtotal) }}</td>
                                    @can('costing.view')<td class="text-right whitespace-nowrap">{{ $order->isShipped() ? rupiah($item->cost) : '-' }}</td>@endcan
                                    @if ($order->returned_at)
                                        <td class="text-right text-sm whitespace-nowrap">refund {{ qty($item->returned_quantity) }}<div class="text-xs text-gray-500">kembali {{ qty($item->restocked_quantity) }}{{ (float) $item->defect_quantity ? ', cacat '.qty($item->defect_quantity) : '' }}</div></td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-gray-50 text-sm">
                            <tr><td colspan="4" class="text-right font-medium">Total</td><td class="text-right font-semibold whitespace-nowrap">{{ rupiah($order->total) }}</td>@can('costing.view')<td class="text-right whitespace-nowrap">{{ $order->isShipped() ? rupiah($order->cost) : '-' }}</td>@endcan @if ($order->returned_at)<td></td>@endif</tr>
                            @if ((float) $order->tax_amount)
                                <tr><td colspan="4" class="text-right text-gray-500">DPP / PPN {{ qty($order->tax_rate) }}%</td><td class="text-right whitespace-nowrap text-gray-500">{{ rupiah($order->dpp) }} / {{ rupiah($order->tax_amount) }}</td>@can('costing.view')<td></td>@endcan @if ($order->returned_at)<td></td>@endif</tr>
                            @endif
                            @if ($order->returned_at)
                                <tr><td colspan="4" class="text-right text-gray-500">Nilai retur</td><td class="text-right whitespace-nowrap text-red-600">−{{ rupiah($order->returned_total) }}</td>@can('costing.view')<td class="text-right whitespace-nowrap text-red-600">−{{ rupiah($order->returned_cost) }}</td>@endcan<td></td></tr>
                            @endif
                        </tfoot>
                    </table>
                </div>
            </x-card>

            @can('marketplace.manage')
                @if ($canReturn)
                    <x-card title="Terima Barang Retur" :class="$order->status === MarketplaceOrderStatus::ReturnRequested ? 'border-amber-300' : ''">
                        <form method="POST" action="{{ route('marketplace-orders.return', $order) }}" x-data="{ condition: '{{ old('condition', 'good') }}', setBack(v) { this.$root.querySelectorAll('[data-back]').forEach(i => i.value = v ? i.dataset.back : 0); this.$root.querySelectorAll('[data-defect]').forEach(i => i.value = this.condition === 'defect' ? i.dataset.defect : 0); } }" data-confirm="Penjualan bagian yang direfund dibalik dan barang yang kembali masuk {{ $order->store->warehouse->name }}. Retur hanya bisa dicatat sekali." data-confirm-button="Ya, catat retur">
                            @csrf
                            <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <x-form.select name="condition" label="Kondisi Paket Saat Dibuka" tip="Sesuai: barang kembali ke stok. Cacat: masuk stok cacat. Bukan barang kita / kosong / tidak sampai: barang tidak masuk stok dan nilainya dicatat sebagai kerugian, lalu bisa diajukan banding." :options="MarketplaceReturnCondition::options()" :placeholder="false" required x-model="condition"
                                               x-on:change="setBack(['good', 'defect'].includes(condition))" />
                                <x-form.input name="refund_amount" label="Nilai Refund ke Pembeli (opsional)" data-money help="Isi bila platform merefund sebagian (mis. kompensasi barang kurang). Kosong = sesuai qty direfund." tip="Nominal yang dikembalikan platform ke pembeli. Penjualan & PPN dibalik sebesar nilai ini." />
                            </div>
                            <div x-show="['wrong_item', 'empty', 'not_received'].includes(condition)" class="mb-3 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                                Barang kita tidak kembali: isi <strong>Kembali = 0</strong> (barang asing tidak dimasukkan ke stok). Nilai HPP barang yang hilang dicatat sebagai <em>Kerugian Retur Marketplace</em>.
                                Segera ajukan banding di Seller Center dengan video unboxing & foto paket/resi, lalu catat hasilnya di halaman ini.
                            </div>
                            <p class="mb-3 text-sm text-gray-500">Per baris: qty direfund ke pembeli (mengurangi penjualan & piutang), qty barang <strong>kita</strong> yang benar-benar kembali ke gudang, dan dari yang kembali berapa yang cacat (masuk stok cacat). Refund tanpa barang kembali: isi refund saja.</p>
                            <div class="overflow-x-auto">
                                <table class="table-base">
                                    <thead><tr><th>Barang</th><th class="text-right">Qty</th><th class="w-28">Direfund <x-tip title="Direfund" text="Jumlah pcs yang uangnya dikembalikan ke pembeli." /></th><th class="w-28">Kembali <x-tip title="Kembali" text="Jumlah pcs barang KITA yang benar-benar diterima kembali. Isi 0 bila paket kosong atau barang asing." /></th><th class="w-28">Cacat <x-tip title="Cacat" text="Bagian dari barang kembali yang rusak. Masuk stok cacat, bukan stok jual." /></th></tr></thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach ($order->items as $item)
                                            <tr>
                                                <td>{{ $item->label() }}</td>
                                                <td class="text-right">{{ qty($item->quantity) }}</td>
                                                <td><input type="number" step="0.01" min="0" max="{{ (float) $item->quantity }}" name="lines[{{ $item->id }}][returned]" value="{{ old("lines.{$item->id}.returned", (float) $item->quantity) }}" class="form-control"></td>
                                                <td><input type="number" step="0.01" min="0" max="{{ (float) $item->quantity }}" name="lines[{{ $item->id }}][restocked]" value="{{ old("lines.{$item->id}.restocked", (float) $item->quantity) }}" data-back="{{ (float) $item->quantity }}" class="form-control"></td>
                                                <td><input type="number" step="0.01" min="0" name="lines[{{ $item->id }}][defect]" value="{{ old("lines.{$item->id}.defect", 0) }}" data-defect="{{ (float) $item->quantity }}" class="form-control"></td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                                <x-form.input name="date" label="Tanggal Diterima" :value="today()->toDateString()" data-date required />
                                <x-form.input name="reason" label="Alasan Retur" :value="$order->return_reason" class="sm:col-span-2" />
                            </div>
                            <div class="mt-4 text-right"><button type="submit" class="btn-primary">Catat Retur</button></div>
                        </form>
                    </x-card>
                @endif
            @endcan

            @can('marketplace.manage')
                @if ($order->canOpenClaim())
                    <x-card title="Banding / Klaim ke Platform" class="border-red-300" tip="Ajukan banding di Seller Center dengan bukti video unboxing dan foto paket, lalu catat di sini agar bisa dipantau sampai ada keputusan.">
                        <p class="text-sm text-gray-600">Retur ini bermasalah ({{ $order->return_condition?->label() }}{{ (float) $order->returned_lost_cost ? ', HPP hilang '.rupiah($order->returned_lost_cost) : '' }}).
                            Ajukan banding di Seller Center {{ $order->store->platform->label() }} sebelum batas waktunya, lampirkan video unboxing, foto paket & label resi retur.</p>
                        <div class="mt-3"><x-reason-form :action="route('marketplace-orders.claim', $order)" title="Catat banding?" text="Tulis nomor tiket / ringkasan bukti yang diajukan." placeholder="No. tiket & bukti" button="Catat banding" :danger="false" class="btn-primary">Catat Banding Diajukan</x-reason-form></div>
                    </x-card>
                @elseif ($order->claim_status === MarketplaceClaimStatus::Open)
                    <x-card title="Hasil Banding" class="border-amber-300">
                        <form method="POST" action="{{ route('marketplace-orders.claim.resolve', $order) }}" class="grid grid-cols-1 gap-4 sm:grid-cols-4" x-data="{ result: 'won' }">
                            @csrf
                            <x-form.select name="result" label="Keputusan" tip="Diterima: kompensasi menjadi piutang marketplace dan mengurangi kerugian. Ditolak: kerugian tetap." :options="['won' => 'Diterima (dapat kompensasi)', 'lost' => 'Ditolak']" :placeholder="false" x-model="result" />
                            <div x-show="result === 'won'"><x-form.input name="claim_amount" label="Nilai Kompensasi" tip="Nominal penggantian dari platform. Akan tertutup saat ikut dicairkan di laporan penghasilan." data-money :value="(float) $order->returned_total" /></div>
                            <x-form.input name="date" label="Tanggal Keputusan" :value="today()->toDateString()" data-date required />
                            <x-form.input name="notes" label="Catatan" />
                            <div class="sm:col-span-4 text-right"><button type="submit" class="btn-primary">Simpan Hasil Banding</button></div>
                        </form>
                        <p class="mt-2 text-xs text-gray-500">Kompensasi dicatat sebagai piutang marketplace (mengurangi kerugian retur) dan tertutup saat dana kompensasi ikut dalam pencairan.</p>
                    </x-card>
                @endif
            @endcan

            @if ($order->settlementLines->isNotEmpty())
                <x-card title="Pencairan Dana" :padding="false">
                    <div class="overflow-x-auto">
                        <table class="table-base">
                            <thead><tr><th>Dokumen</th><th class="text-right">Nilai Pesanan</th><th class="text-right">Voucher</th><th class="text-right">Biaya</th><th class="text-right">Ongkir</th><th class="text-right">Penyesuaian</th><th class="text-right">Dana Cair</th></tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($order->settlementLines as $line)
                                    <tr>
                                        <td><a href="{{ route('marketplace-settlements.show', $line->settlement) }}" class="text-indigo-600 hover:underline">{{ $line->settlement->number }}</a> <x-status-badge :status="$line->settlement->status" /><div class="text-xs text-gray-500">{{ tanggal($line->settlement->date) }}</div></td>
                                        <td class="text-right whitespace-nowrap">{{ rupiah($line->order_amount) }}</td>
                                        <td class="text-right whitespace-nowrap">{{ rupiah($line->voucher) }}</td>
                                        <td class="text-right whitespace-nowrap">{{ rupiah($line->fees()) }}</td>
                                        <td class="text-right whitespace-nowrap">{{ rupiah($line->shipping_fee) }}</td>
                                        <td class="text-right whitespace-nowrap">{{ rupiah($line->adjustment) }}</td>
                                        <td class="text-right font-medium whitespace-nowrap">{{ rupiah($line->net_amount) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-card>
            @elseif ($order->outstandingReceivable() > 0)
                <p class="text-sm text-amber-600">Piutang marketplace {{ rupiah($order->outstandingReceivable()) }} belum cair. Impor laporan penghasilan di menu Pencairan Dana.</p>
            @endif
        </div>

        <x-journal-entries :sources="$order" class="lg:col-span-3" />
        <x-activity-log :activities="$order->activities" class="lg:col-span-3" />
    </div>
</x-layouts.app>
