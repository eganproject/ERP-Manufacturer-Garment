<?php

namespace App\Services;

use App\Enums\ProductionOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\SalesOrderItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Laporan HPP & margin per artikel: HPP standar (BOM) vs HPP aktual SPK selesai, dibandingkan harga jual.
 */
class CostingReport
{
    /**
     * @return array{products: Collection, orders: Collection, totals: array<string, float>}
     */
    public function build(Carbon $from, Carbon $to, ?int $productId = null): array
    {
        $orders = ProductionOrder::with(['product', 'bom.items.material'])
            ->where('status', ProductionOrderStatus::Completed)
            ->whereBetween('completed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($productId, fn ($q, $id) => $q->where('product_id', $id))
            ->orderByDesc('completed_at')
            ->get();

        // Harga jual rata-rata dari SO yang dikonfirmasi pada periode yang sama
        $sales = SalesOrderItem::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_items.sales_order_id')
            ->join('product_variants', 'product_variants.id', '=', 'sales_order_items.product_variant_id')
            ->whereNotIn('sales_orders.status', [SalesOrderStatus::Draft->value, SalesOrderStatus::Cancelled->value])
            ->whereBetween('sales_orders.order_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('product_variants.product_id, SUM(sales_order_items.quantity) as qty, SUM(sales_order_items.subtotal) as amount')
            ->groupBy('product_variants.product_id')
            ->get()
            ->keyBy('product_id');

        $products = Product::with(['activeBom.items.material'])
            ->where(fn ($q) => $q->where('is_active', true)->orWhereIn('id', $orders->pluck('product_id')))
            ->when($productId, fn ($q, $id) => $q->whereKey($id))
            ->orderBy('name')
            ->get()
            ->map(function (Product $product) use ($orders, $sales) {
                $produced = $orders->where('product_id', $product->id);
                $finished = (float) $produced->sum('finished_quantity');
                $actualTotal = (float) $produced->sum('total_cost');
                $actualUnit = $finished > 0 ? round($actualTotal / $finished, 2) : null;
                $standardUnit = $product->activeBom?->standardUnitCost();

                $sold = $sales->get($product->id);
                $sellingPrice = $sold && (float) $sold->qty > 0
                    ? round((float) $sold->amount / (float) $sold->qty, 2)
                    : (float) $product->price;

                $basis = $actualUnit ?? $standardUnit;
                $margin = $basis !== null ? round($sellingPrice - $basis, 2) : null;

                return [
                    'product' => $product,
                    'spk_count' => $produced->count(),
                    'finished' => $finished,
                    'material_unit' => $finished > 0 ? round((float) $produced->sum('material_cost') / $finished, 2) : null,
                    'conversion_unit' => $finished > 0 ? round((float) $produced->sum('conversion_cost') / $finished, 2) : null,
                    'actual_unit' => $actualUnit,
                    'standard_unit' => $standardUnit,
                    'variance' => $actualUnit !== null && $standardUnit ? round($actualUnit - $standardUnit, 2) : null,
                    'selling_price' => $sellingPrice,
                    'price_source' => $sold && (float) $sold->qty > 0 ? 'so' : 'master',
                    'margin' => $margin,
                    'margin_percent' => $margin !== null && $sellingPrice > 0 ? round($margin / $sellingPrice * 100, 1) : null,
                ];
            })
            ->filter(fn ($row) => $row['spk_count'] > 0 || $row['standard_unit'] !== null)
            ->values();

        $finished = (float) $orders->sum('finished_quantity');

        return [
            'products' => $products,
            'orders' => $orders,
            'totals' => [
                'spk' => $orders->count(),
                'finished' => $finished,
                'total_cost' => (float) $orders->sum('total_cost'),
                'average_unit' => $finished > 0 ? round((float) $orders->sum('total_cost') / $finished, 2) : 0,
            ],
        ];
    }
}
