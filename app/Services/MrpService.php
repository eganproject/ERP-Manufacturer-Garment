<?php

namespace App\Services;

use App\Enums\ProductionOrderStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Models\ActivityLog;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductionMaterial;
use App\Models\ProductionOrderItem;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SalesOrderItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Material Requirements Planning: kebutuhan bahan dari SO terbuka + BOM, dibandingkan stok dan PO berjalan.
 *
 * Alur hitung:
 * 1. Kebutuhan barang jadi per varian = sisa SO − stok barang jadi − rencana SPK (draft/dalam proses).
 * 2. Kebutuhan bahan kotor = kebutuhan bersih barang jadi × BOM aktif + bahan SPK draft yang belum dikeluarkan.
 * 3. Kekurangan bahan = kebutuhan kotor + stok minimum − stok gudang − sisa PO (draft/dipesan/sebagian).
 */
class MrpService
{
    /**
     * @param  list<int>|null  $salesOrderIds  batasi ke SO tertentu (null = semua SO terbuka)
     * @return array{products: Collection, materials: Collection, warnings: list<string>, sales_order_count: int}
     */
    public function calculate(?array $salesOrderIds = null, bool $includeSafetyStock = true): array
    {
        $demand = SalesOrderItem::query()
            ->whereHas('salesOrder', fn ($q) => $q->whereIn('status', SalesOrderStatus::open())
                ->when($salesOrderIds, fn ($w) => $w->whereIn('id', $salesOrderIds)))
            ->selectRaw('product_variant_id, SUM(quantity - delivered_quantity) as qty, COUNT(DISTINCT sales_order_id) as so_count')
            ->groupBy('product_variant_id')
            ->get()
            ->keyBy('product_variant_id');

        $salesOrderCount = SalesOrderItem::query()
            ->whereHas('salesOrder', fn ($q) => $q->whereIn('status', SalesOrderStatus::open())
                ->when($salesOrderIds, fn ($w) => $w->whereIn('id', $salesOrderIds)))
            ->distinct()
            ->count('sales_order_id');

        $planned = ProductionOrderItem::query()
            ->whereHas('productionOrder', fn ($q) => $q->whereIn('status', [ProductionOrderStatus::Draft, ProductionOrderStatus::InProgress]))
            ->selectRaw('product_variant_id, SUM(quantity - finished_quantity) as qty')
            ->groupBy('product_variant_id')
            ->pluck('qty', 'product_variant_id');

        $variants = ProductVariant::with('product.activeBom.items')
            ->whereIn('id', $demand->keys())
            ->get();

        $productRows = $variants->map(function (ProductVariant $variant) use ($demand, $planned) {
            $ordered = max(0, (float) $demand[$variant->id]->qty);
            $stock = max(0, (float) $variant->stock);
            $inProduction = max(0, (float) ($planned[$variant->id] ?? 0));

            return [
                'variant' => $variant,
                'product' => $variant->product,
                'ordered' => $ordered,
                'stock' => $stock,
                'planned' => $inProduction,
                'net' => max(0.0, round($ordered - $stock - $inProduction, 2)),
            ];
        })->filter(fn ($row) => $row['ordered'] > 0)
            ->sortBy(fn ($row) => [$row['product']->name, $row['variant']->id])
            ->values();

        // Kebutuhan bahan dari kebutuhan bersih barang jadi
        $gross = [];
        $sources = [];
        $warnings = [];

        foreach ($productRows->where('net', '>', 0)->groupBy(fn ($row) => $row['product']->id) as $rows) {
            /** @var Product $product */
            $product = $rows->first()['product'];
            $netQty = $rows->sum('net');

            if (! $product->activeBom) {
                $warnings[] = "Produk {$product->code} - {$product->name} belum memiliki BOM aktif, kebutuhan bahannya tidak dihitung.";

                continue;
            }

            foreach ($product->activeBom->requirementsFor($netQty) as $materialId => $qty) {
                $gross[$materialId] = ($gross[$materialId] ?? 0) + $qty;
                $sources[$materialId]['so'] = ($sources[$materialId]['so'] ?? 0) + $qty;
            }
        }

        // Bahan untuk SPK draft yang belum dikeluarkan dari gudang
        $draftMaterials = ProductionMaterial::query()
            ->whereHas('productionOrder', fn ($q) => $q->where('status', ProductionOrderStatus::Draft))
            ->selectRaw('material_id, SUM(required_quantity - issued_quantity) as qty')
            ->groupBy('material_id')
            ->pluck('qty', 'material_id');

        foreach ($draftMaterials as $materialId => $qty) {
            if ((float) $qty > 0) {
                $gross[$materialId] = ($gross[$materialId] ?? 0) + (float) $qty;
                $sources[$materialId]['spk'] = (float) $qty;
            }
        }

        $onOrder = PurchaseOrderItem::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->whereIn('purchase_orders.status', [PurchaseOrderStatus::Ordered->value, PurchaseOrderStatus::Partial->value])
            ->selectRaw('material_id, SUM(quantity - received_quantity) as qty')
            ->groupBy('material_id')
            ->pluck('qty', 'material_id');

        $draftPo = PurchaseOrderItem::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            // PO draft & menunggu persetujuan: sudah direncanakan tetapi belum dipesan
            ->whereIn('purchase_orders.status', [PurchaseOrderStatus::Draft->value, PurchaseOrderStatus::PendingApproval->value])
            ->selectRaw('material_id, SUM(quantity) as qty')
            ->groupBy('material_id')
            ->pluck('qty', 'material_id');

        $lastSuppliers = $this->lastSuppliers(array_keys($gross));

        $materialRows = Material::with(['unit', 'defaultSupplier'])
            ->whereIn('id', array_keys($gross))
            ->get()
            ->map(function (Material $material) use ($gross, $sources, $onOrder, $draftPo, $includeSafetyStock, $lastSuppliers) {
                $required = round($gross[$material->id], 2);
                $safety = $includeSafetyStock ? (float) $material->min_stock : 0;
                $stock = (float) $material->stock;
                $ordered = max(0, (float) ($onOrder[$material->id] ?? 0));
                $drafted = max(0, (float) ($draftPo[$material->id] ?? 0));
                $shortage = max(0.0, round($required + $safety - $stock - $ordered - $drafted, 2));

                return [
                    'material' => $material,
                    'from_sales' => round($sources[$material->id]['so'] ?? 0, 2),
                    'from_draft_spk' => round($sources[$material->id]['spk'] ?? 0, 2),
                    'required' => $required,
                    'safety' => $safety,
                    'stock' => $stock,
                    'on_order' => $ordered,
                    'draft_po' => $drafted,
                    'shortage' => $shortage,
                    'suggested_quantity' => $shortage > 0 ? ceil($shortage) : 0,
                    'supplier_id' => $material->default_supplier_id ?? ($lastSuppliers[$material->id] ?? null),
                    'price' => (float) $material->price,
                ];
            })
            ->sortBy(fn ($row) => [$row['shortage'] > 0 ? 0 : 1, $row['material']->name])
            ->values();

        return [
            'products' => $productRows,
            'materials' => $materialRows,
            'warnings' => $warnings,
            'sales_order_count' => $salesOrderCount,
        ];
    }

    /**
     * Buat PO draft dari baris kekurangan bahan, dikelompokkan per supplier.
     *
     * @param  list<array{material_id: int|string, supplier_id: int|string, quantity: float|string, price: float|string}>  $lines
     * @return Collection<int, PurchaseOrder>
     */
    public function createPurchaseOrders(array $lines, ?string $notes = null): Collection
    {
        $lines = array_values(array_filter($lines, fn ($line) => (float) ($line['quantity'] ?? 0) > 0));

        if (empty($lines)) {
            throw ValidationException::withMessages(['lines' => 'Pilih minimal satu bahan dengan jumlah lebih dari 0.']);
        }

        foreach ($lines as $i => $line) {
            if (empty($line['supplier_id'])) {
                $name = Material::find($line['material_id'])?->name ?? 'bahan';

                throw ValidationException::withMessages(["lines.$i.supplier_id" => "Pilih supplier untuk {$name}."]);
            }
        }

        return DB::transaction(function () use ($lines, $notes) {
            return collect($lines)->groupBy('supplier_id')->map(function (Collection $rows, $supplierId) use ($notes) {
                $items = $rows->groupBy('material_id')->map(function (Collection $same, $materialId) {
                    $quantity = round($same->sum(fn ($r) => (float) $r['quantity']), 2);
                    $price = round((float) $same->first()['price'], 2);

                    return [
                        'material_id' => (int) $materialId,
                        'quantity' => $quantity,
                        'price' => $price,
                        'subtotal' => round($quantity * $price, 2),
                    ];
                })->values();

                $order = PurchaseOrder::create([
                    'number' => DocumentNumber::generate(PurchaseOrder::class, 'PO'),
                    'supplier_id' => (int) $supplierId,
                    'order_date' => today(),
                    'status' => PurchaseOrderStatus::Draft,
                    'total' => $items->sum('subtotal'),
                    'notes' => $notes ?: 'Dibuat otomatis dari perencanaan kebutuhan bahan (MRP).',
                    'created_by' => auth()->id(),
                ]);
                $order->items()->createMany($items->all());

                ActivityLog::record('created', "Membuat PO {$order->number} dari MRP", $order);

                return $order;
            })->values();
        });
    }

    /**
     * Supplier terakhir per bahan dari PO yang tidak dibatalkan.
     *
     * @param  list<int>  $materialIds
     * @return array<int, int>
     */
    private function lastSuppliers(array $materialIds): array
    {
        if (empty($materialIds)) {
            return [];
        }

        return PurchaseOrderItem::query()
            ->join('purchase_orders', 'purchase_orders.id', '=', 'purchase_order_items.purchase_order_id')
            ->whereIn('material_id', $materialIds)
            ->where('purchase_orders.status', '!=', PurchaseOrderStatus::Cancelled->value)
            ->orderBy('purchase_orders.order_date')
            ->orderBy('purchase_orders.id')
            ->get(['purchase_order_items.material_id', 'purchase_orders.supplier_id'])
            ->pluck('supplier_id', 'material_id')
            ->all();
    }
}
