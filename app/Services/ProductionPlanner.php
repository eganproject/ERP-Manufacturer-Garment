<?php

namespace App\Services;

use App\Enums\ProductionOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Models\ActivityLog;
use App\Models\Bom;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionOrderItem;
use App\Models\ProductVariant;
use App\Models\SalesOrderItem;
use App\Models\Warehouse;
use App\Services\Marketplace\MarketplaceFulfillment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Rencana produksi barang jadi: permintaan dari SO terbuka + pesanan marketplace Perlu Dikirim (PO & ready stock),
 * dikurangi stok barang jadi dan SPK yang sedang berjalan. Dipakai halaman Rencana Produksi dan MRP.
 */
class ProductionPlanner
{
    public function __construct(private MarketplaceFulfillment $fulfillment) {}

    /**
     * @param  list<int>|null  $salesOrderIds  batasi SO (null = semua SO terbuka)
     * @return Collection<int, array{variant: ProductVariant, product: Product, sales: float, marketplace: float, preorder: float,
     *                    ordered: float, stock: float, planned: float, net: float, ship_by: Carbon|null, orders: int}>
     */
    public function rows(?array $salesOrderIds = null, bool $includeSales = true, bool $includeMarketplace = true): Collection
    {
        $sales = collect();
        $salesDates = collect();

        if ($includeSales) {
            $open = fn ($q) => $q->whereIn('status', SalesOrderStatus::open())->when($salesOrderIds, fn ($w) => $w->whereIn('id', $salesOrderIds));

            $items = SalesOrderItem::with('salesOrder:id,delivery_date')->whereHas('salesOrder', $open)->get();
            $sales = $items->groupBy('product_variant_id')->map(fn ($rows) => [
                'qty' => round($rows->sum(fn ($i) => (float) $i->quantity - (float) $i->delivered_quantity), 2),
                'orders' => $rows->pluck('sales_order_id')->unique()->count(),
            ]);
            $salesDates = $items->filter(fn ($i) => (float) $i->quantity > (float) $i->delivered_quantity && $i->salesOrder->delivery_date)
                ->groupBy('product_variant_id')->map(fn ($rows) => $rows->map(fn ($i) => $i->salesOrder->delivery_date)->min());
        }

        $marketplace = $includeMarketplace ? $this->fulfillment->demand() : collect();

        $planned = ProductionOrderItem::query()
            ->whereHas('productionOrder', fn ($q) => $q->whereIn('status', [ProductionOrderStatus::Draft, ProductionOrderStatus::InProgress]))
            ->selectRaw('product_variant_id, SUM(quantity - finished_quantity) as qty')
            ->groupBy('product_variant_id')
            ->pluck('qty', 'product_variant_id');

        $variantIds = $sales->keys()->merge($marketplace->keys())->unique();

        return ProductVariant::with('product.activeBom.items')
            ->whereIn('id', $variantIds)
            ->get()
            ->map(function (ProductVariant $variant) use ($sales, $salesDates, $marketplace, $planned) {
                $fromSales = max(0, (float) ($sales[$variant->id]['qty'] ?? 0));
                $mp = $marketplace[$variant->id] ?? null;
                $fromMarketplace = max(0, (float) ($mp['qty'] ?? 0));
                $ordered = round($fromSales + $fromMarketplace, 2);
                $stock = max(0, (float) $variant->stock);
                $inProduction = max(0, (float) ($planned[$variant->id] ?? 0));
                $dates = array_filter([$mp['ship_by'] ?? null, isset($salesDates[$variant->id]) ? Carbon::parse($salesDates[$variant->id]) : null]);

                return [
                    'variant' => $variant,
                    'product' => $variant->product,
                    'sales' => $fromSales,
                    'marketplace' => $fromMarketplace,
                    'preorder' => (float) ($mp['preorder'] ?? 0),
                    'ordered' => $ordered,
                    'stock' => $stock,
                    'planned' => $inProduction,
                    'net' => max(0.0, round($ordered - $stock - $inProduction, 2)),
                    'ship_by' => $dates ? collect($dates)->min() : null,
                    'orders' => (int) ($sales[$variant->id]['orders'] ?? 0) + (int) ($mp['orders'] ?? 0),
                ];
            })
            ->filter(fn ($row) => $row['ordered'] > 0)
            ->sortBy(fn ($row) => [$row['product']->name, $row['variant']->id])
            ->values();
    }

    /** Target selesai SPK: 1 hari sebelum batas kirim terdekat (tidak sebelum hari ini). */
    public static function dueDateFor(?Carbon $shipBy): ?Carbon
    {
        return $shipBy ? Carbon::parse($shipBy)->subDay()->max(today()) : null;
    }

    /**
     * Buat SPK draft per artikel dari rencana produksi.
     *
     * @param  array<int|string, array<int|string, float|string|null>>  $products  product_id => [variant_id => qty]
     * @param  array<int|string, string|null>  $dueDates  product_id => target selesai
     * @return Collection<int, ProductionOrder>
     */
    public function createOrders(array $products, array $dueDates = [], ?int $warehouseId = null): Collection
    {
        $products = collect($products)
            ->map(fn ($variants) => array_filter(array_map('floatval', (array) $variants), fn ($q) => $q > 0))
            ->filter();

        if ($products->isEmpty()) {
            throw ValidationException::withMessages(['products' => 'Pilih minimal satu artikel dengan jumlah produksi lebih dari 0.']);
        }

        return DB::transaction(function () use ($products, $dueDates, $warehouseId) {
            return $products->map(function (array $variants, $productId) use ($dueDates, $warehouseId) {
                $bom = Bom::with('product')->where('product_id', $productId)->where('is_active', true)->latest()->first();

                if (! $bom) {
                    $name = Product::find($productId)?->name ?? 'Artikel';

                    throw ValidationException::withMessages(['products' => "{$name} belum memiliki BOM aktif, SPK tidak bisa dibuat."]);
                }

                $due = ! empty($dueDates[$productId]) ? Carbon::parse($dueDates[$productId])->max(today()) : null;

                $order = ProductionOrder::create([
                    'number' => DocumentNumber::generate(ProductionOrder::class, 'SPK', today()),
                    'product_id' => (int) $productId,
                    'bom_id' => $bom->id,
                    'start_date' => today(),
                    'due_date' => $due,
                    'status' => ProductionOrderStatus::Draft,
                    'warehouse_id' => Warehouse::resolveId($warehouseId),
                    'notes' => 'Dibuat dari Rencana Produksi (pesanan marketplace / SO).',
                    'created_by' => auth()->id(),
                ]);
                app(ProductionService::class)->syncItems($order, $variants);
                ActivityLog::record('created', "Membuat SPK {$order->number} dari Rencana Produksi", $order);

                return $order;
            })->values();
        });
    }
}
