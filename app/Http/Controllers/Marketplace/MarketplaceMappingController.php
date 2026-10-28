<?php

namespace App\Http\Controllers\Marketplace;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\MarketplaceOrderItem;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceStore;
use App\Models\ProductVariant;
use App\Services\Marketplace\MarketplaceFulfillment;
use App\Services\Marketplace\MarketplaceOrderService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;

/** Pemetaan SKU toko marketplace ke varian artikel ERP. */
class MarketplaceMappingController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:marketplace.view', only: ['index']),
            new Middleware('can:marketplace.manage', except: ['index']),
        ];
    }

    public function index(Request $request)
    {
        $storeId = $request->integer('store_id') ?: null;

        // SKU di pesanan yang belum dipetakan
        $unmapped = MarketplaceOrderItem::query()
            ->join('marketplace_orders', 'marketplace_orders.id', '=', 'marketplace_order_items.marketplace_order_id')
            ->whereNull('marketplace_order_items.product_variant_id')
            ->when($storeId, fn ($q) => $q->where('marketplace_orders.marketplace_store_id', $storeId))
            ->selectRaw('marketplace_orders.marketplace_store_id as store_id, marketplace_order_items.marketplace_sku as sku, MAX(marketplace_order_items.product_name) as name, MAX(marketplace_order_items.variation) as variation, COUNT(DISTINCT marketplace_orders.id) as orders')
            ->groupBy('marketplace_orders.marketplace_store_id', 'marketplace_order_items.marketplace_sku')
            ->orderByDesc('orders')
            ->get();

        $mappings = MarketplaceProduct::with(['store', 'variant.product'])
            ->when($storeId, fn ($q) => $q->where('marketplace_store_id', $storeId))
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('marketplace_sku', 'like', "%{$s}%")->orWhere('marketplace_name', 'like', "%{$s}%")))
            ->when($request->type === 'preorder', fn ($q) => $q->where('preorder_days', '>', 0))
            ->when($request->type === 'ready', fn ($q) => $q->where('preorder_days', 0))
            ->orderBy('marketplace_store_id')->orderBy('marketplace_sku')
            ->paginate(30)->withQueryString();

        return view('marketplace.mappings.index', [
            'mappings' => $mappings,
            'unmapped' => $unmapped,
            'stores' => MarketplaceStore::options(false),
            'storeId' => $storeId,
            'variants' => ProductVariant::with('product')->get()->sortBy('sku')->mapWithKeys(fn ($v) => [$v->id => $v->sku.' · '.$v->display_name]),
        ]);
    }

    public function store(Request $request, MarketplaceOrderService $service)
    {
        $data = $request->validate([
            'marketplace_store_id' => ['required', 'exists:marketplace_stores,id'],
            'marketplace_sku' => ['required', 'string', 'max:100'],
            'marketplace_name' => ['nullable', 'string', 'max:255'],
            'product_variant_id' => ['required', 'exists:product_variants,id'],
            'units_per_item' => ['nullable', 'numeric', 'min:0.01'],
            'preorder_days' => ['nullable', 'integer', 'min:0', 'max:90'],
        ], [], ['marketplace_sku' => 'SKU marketplace', 'product_variant_id' => 'varian ERP', 'units_per_item' => 'isi per paket', 'preorder_days' => 'hari PO']);

        $updated = DB::transaction(function () use ($data, $service) {
            $mapping = MarketplaceProduct::updateOrCreate(
                ['marketplace_store_id' => $data['marketplace_store_id'], 'marketplace_sku' => trim($data['marketplace_sku'])],
                ['product_variant_id' => $data['product_variant_id'], 'marketplace_name' => $data['marketplace_name'] ?? null, 'units_per_item' => $data['units_per_item'] ?? 1,
                    'preorder_days' => (int) ($data['preorder_days'] ?? 0)],
            );
            ActivityLog::record('mapped', "Mapping SKU {$mapping->marketplace_sku} → {$mapping->variant->sku}", $mapping->store);

            return $service->applyMapping($mapping);
        });

        return back()->with('success', 'Mapping SKU disimpan'.($updated ? ", {$updated} baris pesanan yang belum dikirim ikut diperbarui." : '.'));
    }

    /** Petakan otomatis SKU yang sama persis dengan SKU varian ERP. */
    public function auto(Request $request, MarketplaceOrderService $service)
    {
        $request->validate(['store_id' => ['nullable', 'integer']]);
        $count = 0;

        DB::transaction(function () use ($request, $service, &$count) {
            $rows = MarketplaceOrderItem::query()
                ->join('marketplace_orders', 'marketplace_orders.id', '=', 'marketplace_order_items.marketplace_order_id')
                ->whereNull('marketplace_order_items.product_variant_id')->whereNotNull('marketplace_order_items.marketplace_sku')
                ->when($request->integer('store_id'), fn ($q, $id) => $q->where('marketplace_orders.marketplace_store_id', $id))
                ->select('marketplace_orders.marketplace_store_id', 'marketplace_order_items.marketplace_sku')->distinct()->get();

            foreach ($rows as $row) {
                if ($mapping = MarketplaceOrderService::resolveSku(MarketplaceStore::find($row->marketplace_store_id), $row->marketplace_sku)) {
                    $service->applyMapping($mapping);
                    $count++;
                }
            }
        });

        return back()->with('success', $count ? "{$count} SKU dipetakan otomatis." : 'Tidak ada SKU yang sama dengan SKU varian ERP.');
    }

    /** Atur hari PO (pre-order) untuk banyak SKU sekaligus; batas kirim pesanan terbuka ikut dihitung ulang. */
    public function preorder(Request $request, MarketplaceFulfillment $fulfillment)
    {
        $data = $request->validate([
            'mappings' => ['required', 'array', 'min:1'],
            'mappings.*' => ['integer'],
            'preorder_days' => ['required', 'integer', 'min:0', 'max:90'],
        ], ['mappings.required' => 'Centang minimal satu SKU.'], ['preorder_days' => 'hari PO']);

        $orders = DB::transaction(function () use ($data, $fulfillment) {
            $orders = 0;
            foreach (MarketplaceProduct::with('store')->whereIn('id', $data['mappings'])->get() as $mapping) {
                $mapping->update(['preorder_days' => $data['preorder_days']]);
                $orders += $fulfillment->rescheduleSku($mapping->marketplace_store_id, $mapping->marketplace_sku);
            }
            ActivityLog::record('updated', count($data['mappings']).' SKU marketplace diatur '.($data['preorder_days'] ? "PO {$data['preorder_days']} hari" : 'ready stock'));

            return $orders;
        });

        return back()->with('success', 'Hari PO disimpan'.($orders ? ", batas kirim {$orders} pesanan terbuka dihitung ulang." : '.'));
    }

    public function destroy(MarketplaceProduct $mapping)
    {
        $mapping->delete();

        return back()->with('success', 'Mapping SKU dihapus. Pesanan yang sudah dikirim tidak berubah.');
    }
}
