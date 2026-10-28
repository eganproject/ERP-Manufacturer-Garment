<?php

namespace App\Http\Controllers\Marketplace;

use App\Enums\MarketplaceOrderStatus;
use App\Enums\MarketplacePlatform;
use App\Enums\MarketplaceReturnCondition;
use App\Http\Controllers\Controller;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceStore;
use App\Services\Marketplace\MarketplaceFulfillment;
use App\Services\Marketplace\MarketplaceImporter;
use App\Services\Marketplace\MarketplaceOrderService;
use App\Support\DocumentPdf;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Pesanan TikTok Shop / Shopee: impor file, kirim, selesai, batal, retur. */
class MarketplaceOrderController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:marketplace.view', only: ['index', 'show', 'template', 'pickingList']),
            new Middleware('can:marketplace.manage', except: ['index', 'show', 'template', 'pickingList']),
        ];
    }

    public function index(Request $request, MarketplaceFulfillment $fulfillment)
    {
        $request->validate([
            'status' => ['nullable', Rule::in(array_merge(array_keys(MarketplaceOrderStatus::options()), ['unmapped', 'unsettled', 'claimable', 'claim_open', 'ready', 'waiting', 'preorder']))],
            'platform' => ['nullable', Rule::enum(MarketplacePlatform::class)],
            'store_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $orders = MarketplaceOrder::with(['store', 'items'])
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('order_number', 'like', "%{$s}%")
                ->orWhere('buyer_username', 'like', "%{$s}%")->orWhere('tracking_number', 'like', "%{$s}%")))
            ->when($request->store_id, fn ($q, $id) => $q->where('marketplace_store_id', $id))
            ->when($request->platform, fn ($q, $p) => $q->whereHas('store', fn ($s) => $s->where('platform', $p)))
            ->when($request->from, fn ($q, $d) => $q->where('order_date', '>=', $d))
            ->when($request->to, fn ($q, $d) => $q->where('order_date', '<=', $d.' 23:59:59'))
            ->when($request->status, function ($q, $status) use ($fulfillment) {
                match ($status) {
                    'ready', 'waiting' => $q->whereIn('id', $fulfillment->idsInState($status === 'ready' ? MarketplaceFulfillment::READY : MarketplaceFulfillment::WAITING)),
                    'preorder' => $q->where('is_preorder', true)->whereIn('status', [MarketplaceOrderStatus::Pending, MarketplaceOrderStatus::ToShip]),
                    'unmapped' => $q->whereNull('shipped_at')->whereNotIn('status', [MarketplaceOrderStatus::Cancelled])->whereHas('items', fn ($i) => $i->whereNull('product_variant_id')),
                    'unsettled' => $q->whereNotNull('shipped_at')->whereRaw('ROUND(total - returned_total + (CASE WHEN claim_status = \'won\' THEN claim_amount ELSE 0 END) - settled_receivable, 2) > 0'),
                    'claimable' => $q->whereNotNull('returned_at')->whereNull('claim_status')
                        ->where(fn ($w) => $w->where('returned_lost_cost', '>', 0)->orWhereIn('return_condition', ['wrong_item', 'empty', 'not_received'])),
                    'claim_open' => $q->where('claim_status', 'open'),
                    default => $q->where('status', $status),
                };
            })
            // Pesanan yang belum dikirim diurutkan dari batas kirim terdekat
            ->when(in_array($request->status, ['ready', 'waiting', 'preorder', MarketplaceOrderStatus::ToShip->value], true),
                fn ($q) => $q->orderByRaw('CASE WHEN ship_by IS NULL THEN 1 ELSE 0 END')->orderBy('ship_by'))
            ->latest('order_date')->latest('id')
            ->paginate(25)->withQueryString();

        $counts = MarketplaceOrder::when($request->store_id, fn ($q, $id) => $q->where('marketplace_store_id', $id))
            ->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');

        return view('marketplace.orders.index', [
            'orders' => $orders,
            'counts' => $counts,
            'allocation' => $fulfillment->allocate(),
            'stores' => MarketplaceStore::options(false),
        ]);
    }

    public function show(MarketplaceOrder $marketplaceOrder, MarketplaceFulfillment $fulfillment)
    {
        $marketplaceOrder->load(['store.warehouse', 'items.variant.product', 'settlementLines.settlement', 'activities.user']);

        return view('marketplace.orders.show', ['order' => $marketplaceOrder, 'fulfillment' => $fulfillment->allocate()[$marketplaceOrder->id] ?? null]);
    }

    /** Ubah tanda PO / batas kirim pesanan secara manual. */
    public function schedule(Request $request, MarketplaceOrder $marketplaceOrder, MarketplaceOrderService $service)
    {
        $data = $request->validate([
            'is_preorder' => ['nullable', 'boolean'],
            'ship_by' => ['nullable', 'date'],
        ], [], ['ship_by' => 'batas kirim']);

        $service->reschedule($marketplaceOrder, (bool) ($data['is_preorder'] ?? false), $data['ship_by'] ?? null);

        return back()->with('success', 'Batas kirim pesanan diperbarui.');
    }

    /** Daftar ambil barang (picking list) untuk pesanan Siap Dikirim: total per SKU + rincian pesanan. */
    public function pickingList(Request $request, MarketplaceFulfillment $fulfillment)
    {
        $request->validate(['store_id' => ['nullable', 'integer']]);

        $orders = MarketplaceOrder::with(['store.warehouse', 'items.variant.product'])
            ->whereIn('id', $fulfillment->idsInState(MarketplaceFulfillment::READY))
            ->when($request->integer('store_id'), fn ($q, $id) => $q->where('marketplace_store_id', $id))
            ->orderByRaw('CASE WHEN ship_by IS NULL THEN 1 ELSE 0 END')->orderBy('ship_by')->orderBy('order_date')
            ->get();

        $lines = $orders->flatMap(fn (MarketplaceOrder $order) => $order->items->map(fn ($item) => ['order' => $order, 'item' => $item]))
            ->groupBy(fn ($row) => $row['order']->store->warehouse_id.':'.$row['item']->product_variant_id)
            ->map(fn ($rows) => [
                'variant' => $rows->first()['item']->variant,
                'warehouse' => $rows->first()['order']->store->warehouse,
                'quantity' => $rows->sum(fn ($row) => $row['item']->stockQuantity()),
                'orders' => $rows->pluck('order.id')->unique()->count(),
            ])
            ->sortBy(fn ($line) => [$line['warehouse']->name, $line['variant']->sku])
            ->values();

        return DocumentPdf::stream('pdf.marketplace-picking', [
            'orders' => $orders,
            'lines' => $lines,
            'store' => $request->integer('store_id') ? MarketplaceStore::find($request->integer('store_id')) : null,
        ], 'daftar-ambil-barang-'.today()->format('Ymd'));
    }

    public function importForm()
    {
        return view('marketplace.orders.import', ['stores' => MarketplaceStore::options()]);
    }

    public function import(Request $request, MarketplaceImporter $importer)
    {
        $data = $request->validate([
            'marketplace_store_id' => ['required', Rule::exists('marketplace_stores', 'id')->where('is_active', true)],
            'file' => ['required', 'file', 'max:20480', 'extensions:csv,txt,xlsx,xls'],
        ], [], ['marketplace_store_id' => 'toko', 'file' => 'file pesanan']);

        $store = MarketplaceStore::findOrFail($data['marketplace_store_id']);
        $file = $request->file('file');
        $summary = $importer->importFile($store, $file->getRealPath(), $file->getClientOriginalName());

        return redirect()->route('marketplace-orders.index', ['store_id' => $store->id])
            ->with('success', "Impor {$store->display_name}: {$summary['orders']} pesanan terbaca, {$summary['created']} baru, {$summary['updated']} diperbarui, {$summary['unchanged']} tetap.")
            ->with('import_issues', array_slice(array_merge($summary['errors'], $summary['warnings']), 0, 50));
    }

    public function template()
    {
        return MarketplaceImporter::template(MarketplaceImporter::ORDER_TEMPLATE, [
            '2610250001ABC', 'Perlu Dikirim', '2026-10-25 09:15', '2026-10-25 09:20', '', '', 'pembeli01', 'Budi', 'Bandung', 'J&T', '', 10000, 'TS-BSC-HTM-M', 'Kaos Basic', 'Hitam, M', 2, 99000, 198000, 'Ya', '2026-11-24',
        ], 'template-pesanan-marketplace.xlsx', [
            'Isi pesanan di sheet "Data" (satu baris per produk dalam pesanan; pesanan dengan beberapa produk = beberapa baris dengan No. Pesanan sama).',
            'Lihat sheet "Contoh" untuk format pengisian. Jangan ubah judul kolom.',
            'SKU harus sama dengan SKU varian di ERP atau sudah dipetakan di menu Mapping SKU.',
            'Tanggal boleh format tanggal Excel atau teks 2026-10-25 09:15 / 25/10/2026 09:15.',
            'Simpan sebagai .xlsx (atau .xls / .csv) lalu unggah di menu Impor Pesanan.',
        ]);
    }

    public function ship(Request $request, MarketplaceOrder $marketplaceOrder, MarketplaceOrderService $service)
    {
        $data = $request->validate(['date' => ['nullable', 'date']]);
        $service->ship($marketplaceOrder, $data['date'] ?? today());

        return back()->with('success', "Pesanan {$marketplaceOrder->order_number} dikirim, stok keluar dari gudang toko.");
    }

    /** Kirim banyak pesanan Perlu Dikirim sekaligus (mis. setelah cetak label). */
    public function shipMany(Request $request, MarketplaceOrderService $service)
    {
        $data = $request->validate(['orders' => ['required', 'array', 'min:1'], 'orders.*' => ['integer'], 'date' => ['nullable', 'date']]);
        $done = 0;
        $errors = [];

        foreach (MarketplaceOrder::whereIn('id', $data['orders'])->where('status', MarketplaceOrderStatus::ToShip)->get() as $order) {
            try {
                $service->ship($order, $data['date'] ?? today());
                $done++;
            } catch (ValidationException $e) {
                $errors[] = collect($e->errors())->flatten()->first();
            }
        }

        return back()->with('success', "{$done} pesanan dikirim.")->with('import_issues', $errors);
    }

    public function complete(MarketplaceOrder $marketplaceOrder, MarketplaceOrderService $service)
    {
        $service->complete($marketplaceOrder);

        return back()->with('success', 'Pesanan ditandai selesai.');
    }

    public function cancel(Request $request, MarketplaceOrder $marketplaceOrder, MarketplaceOrderService $service)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'alasan']);
        $service->cancel($marketplaceOrder, $data['reason']);

        return back()->with('success', 'Pesanan dibatalkan.');
    }

    public function requestReturn(Request $request, MarketplaceOrder $marketplaceOrder, MarketplaceOrderService $service)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'alasan']);
        $service->requestReturn($marketplaceOrder, $data['reason']);

        return back()->with('success', 'Pesanan masuk proses retur. Catat penerimaan barang saat paket kembali.');
    }

    public function rejectReturn(Request $request, MarketplaceOrder $marketplaceOrder, MarketplaceOrderService $service)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'alasan']);
        $service->rejectReturn($marketplaceOrder, $data['reason']);

        return back()->with('success', 'Retur dibatalkan, pesanan kembali selesai.');
    }

    public function receiveReturn(Request $request, MarketplaceOrder $marketplaceOrder, MarketplaceOrderService $service)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'condition' => ['required', Rule::enum(MarketplaceReturnCondition::class)],
            'refund_amount' => ['nullable', 'numeric', 'min:0'],
            'lines' => ['required', 'array'],
            'lines.*.returned' => ['nullable', 'numeric', 'min:0'],
            'lines.*.restocked' => ['nullable', 'numeric', 'min:0'],
            'lines.*.defect' => ['nullable', 'numeric', 'min:0'],
        ], [], ['date' => 'tanggal retur', 'lines' => 'barang retur', 'condition' => 'kondisi paket', 'refund_amount' => 'nilai refund']);

        $service->receiveReturn($marketplaceOrder, $data['lines'], $data['date'], $data['reason'] ?? null, $data['condition'], $data['refund_amount'] ?? null);
        $order = $marketplaceOrder->fresh();

        return back()->with('success', 'Retur dicatat: penjualan dibalik, barang yang kembali masuk gudang toko.'
            .($order->canOpenClaim() ? ' Ada barang yang tidak kembali / bukan barang kita: ajukan banding ke platform lalu catat hasilnya.' : ''));
    }

    public function openClaim(Request $request, MarketplaceOrder $marketplaceOrder, MarketplaceOrderService $service)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']], [], ['reason' => 'catatan banding']);
        $service->openClaim($marketplaceOrder, $data['reason']);

        return back()->with('success', 'Banding dicatat. Simpan bukti video unboxing; catat hasilnya saat platform memutuskan.');
    }

    public function resolveClaim(Request $request, MarketplaceOrder $marketplaceOrder, MarketplaceOrderService $service)
    {
        $data = $request->validate([
            'result' => ['required', Rule::in(['won', 'lost'])],
            'claim_amount' => ['nullable', 'numeric', 'min:0'],
            'date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [], ['claim_amount' => 'nilai kompensasi', 'date' => 'tanggal keputusan']);

        $service->resolveClaim($marketplaceOrder, $data['result'] === 'won', (float) ($data['claim_amount'] ?? 0), $data['date'], $data['notes'] ?? null);

        return back()->with('success', $data['result'] === 'won' ? 'Kompensasi dicatat sebagai piutang marketplace; akan tertutup saat ikut cair.' : 'Banding ditolak, kerugian retur tetap dibebankan.');
    }
}
