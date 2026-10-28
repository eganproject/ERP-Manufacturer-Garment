<?php

namespace App\Http\Controllers\Marketplace;

use App\Enums\MarketplaceOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\MarketplaceOrderItem;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceStore;
use App\Support\XlsxWriter;
use Illuminate\Http\Request;

/**
 * Alokasi stok per toko: stok gudang toko − pesanan Perlu Dikirim (semua toko yang memakai gudang sama)
 * = stok tersedia untuk diunggah ke Seller Center (per SKU, dibagi isi paket).
 */
class MarketplaceStockController extends Controller
{
    public function index(Request $request)
    {
        $stores = MarketplaceStore::active()->with('warehouse')->orderBy('platform')->orderBy('name')->get();
        $store = $stores->firstWhere('id', $request->integer('store_id')) ?? $stores->first();
        $rows = $store ? $this->rows($store) : collect();

        if ($store && in_array($request->query('export'), ['xlsx', 'csv'], true)) {
            $data = [['SKU', 'Nama Produk Marketplace', 'Varian ERP', 'Stok Gudang (pcs)', 'Dialokasikan (pcs)', 'Stok Tersedia']];
            foreach ($rows as $r) {
                $data[] = [$r['sku'], $r['name'], $r['variant'], (float) $r['stock'], (float) $r['reserved'], (float) $r['available']];
            }

            return (new XlsxWriter)->sheet('Stok '.$store->platform->label(), $data, textColumns: [0])
                ->download('stok-'.str($store->display_name)->slug().'-'.today()->format('Ymd').'.xlsx');
        }

        return view('marketplace.stock.index', compact('stores', 'store', 'rows'));
    }

    private function rows(MarketplaceStore $store)
    {
        $storeIds = MarketplaceStore::where('warehouse_id', $store->warehouse_id)->pluck('id');

        // Pcs yang sudah dipesan tapi belum dikirim, per varian (semua toko yang memakai gudang ini)
        $reserved = MarketplaceOrderItem::query()
            ->join('marketplace_orders', 'marketplace_orders.id', '=', 'marketplace_order_items.marketplace_order_id')
            ->whereIn('marketplace_orders.marketplace_store_id', $storeIds)
            ->whereIn('marketplace_orders.status', array_map(fn ($s) => $s->value, MarketplaceOrderStatus::reserving()))
            ->whereNotNull('marketplace_order_items.product_variant_id')
            ->selectRaw('marketplace_order_items.product_variant_id as variant_id, SUM(marketplace_order_items.quantity * marketplace_order_items.units_per_item) as qty')
            ->groupBy('marketplace_order_items.product_variant_id')
            ->pluck('qty', 'variant_id');

        return MarketplaceProduct::with(['variant.product', 'variant.warehouseStocks'])
            ->where('marketplace_store_id', $store->id)
            ->orderBy('marketplace_sku')
            ->get()
            ->map(function (MarketplaceProduct $m) use ($store, $reserved) {
                $stock = $m->variant->stockIn($store->warehouse_id);
                $held = (float) ($reserved[$m->product_variant_id] ?? 0);
                $units = max(0.01, (float) $m->units_per_item);

                return [
                    'sku' => $m->marketplace_sku,
                    'name' => $m->marketplace_name,
                    'variant' => $m->variant->sku.' · '.$m->variant->display_name,
                    'units' => (float) $m->units_per_item,
                    'stock' => $stock,
                    'reserved' => $held,
                    'available' => max(0, (int) floor(($stock - $held) / $units)),
                ];
            });
    }
}
