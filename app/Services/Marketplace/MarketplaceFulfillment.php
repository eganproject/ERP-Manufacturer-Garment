<?php

namespace App\Services\Marketplace;

use App\Enums\MarketplaceOrderStatus;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceProduct;
use App\Models\ProductVariant;
use App\Models\WarehouseStock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Pemenuhan pesanan marketplace PO (pre-order) & ready stock.
 *
 * - Jadwal: tiap pesanan punya tanda PO dan batas kirim (dari file, atau tgl bayar + hari PO SKU / hari proses toko).
 * - Alokasi: stok jual gudang toko dibagikan ke pesanan Perlu Dikirim mulai batas kirim terdekat. Satu pesanan
 *   hanya dianggap Siap Dikirim bila SEMUA barangnya kebagian stok; bila tidak, stok tidak ditahan untuknya
 *   (pesanan lain yang bisa lengkap tetap bisa dikirim) dan pesanan itu Menunggu Produksi.
 * - Alokasi dihitung ulang setiap kali dibaca, jadi otomatis ikut berubah saat hasil SPK masuk gudang.
 */
class MarketplaceFulfillment
{
    public const READY = 'ready';

    public const WAITING = 'waiting';

    public const UNMAPPED = 'unmapped';

    /** Batas kirim ≤ sekian hari lagi dianggap mendesak. */
    public const URGENT_DAYS = 3;

    /** Hari PO bawaan bila file menandai pre-order tetapi SKU belum diberi jumlah hari. */
    public const DEFAULT_PREORDER_DAYS = 30;

    /** @var array<int, array{state: string, short: array<int, float>}>|null */
    private ?array $allocation = null;

    /**
     * Tentukan tanda PO & batas kirim pesanan.
     *
     * @param  bool|null  $preorder  dari file (null = tentukan dari SKU / jarak batas kirim)
     * @param  Carbon|string|null  $shipBy  batas kirim dari file / input manual (menjadi tetap)
     */
    public function schedule(MarketplaceOrder $order, ?bool $preorder = null, Carbon|string|null $shipBy = null): void
    {
        if ($order->isShipped() || $order->status === MarketplaceOrderStatus::Cancelled) {
            return;
        }

        $order->loadMissing('store', 'items');
        $base = Carbon::parse($order->paid_at ?? $order->order_date)->startOfDay();
        $handling = (int) $order->store->handling_days;
        $skuDays = $this->preorderDays($order);

        if ($shipBy) {
            $order->ship_by = Carbon::parse($shipBy)->startOfDay();
            $order->ship_by_fixed = true;
        }

        $order->is_preorder = $preorder ?? ($skuDays > 0
            || ($order->ship_by_fixed && $order->ship_by && $base->diffInDays($order->ship_by, false) > $handling + self::URGENT_DAYS));

        if (! $order->ship_by_fixed) {
            $days = $order->is_preorder ? ($skuDays ?: self::DEFAULT_PREORDER_DAYS) : $handling;
            $order->ship_by = $base->copy()->addDays($days);
        }

        if ($order->isDirty(['is_preorder', 'ship_by', 'ship_by_fixed'])) {
            $order->save();
        }
        $this->allocation = null;
    }

    /** Hari PO terlama dari SKU pesanan (0 = semua ready stock). */
    public function preorderDays(MarketplaceOrder $order): int
    {
        $skus = $order->items->pluck('marketplace_sku')->filter()->unique();

        if ($skus->isEmpty()) {
            return 0;
        }

        return (int) MarketplaceProduct::where('marketplace_store_id', $order->marketplace_store_id)
            ->whereIn('marketplace_sku', $skus)->max('preorder_days');
    }

    /** Hitung ulang jadwal pesanan terbuka yang memakai SKU ini (setelah hari PO di mapping diubah). */
    public function rescheduleSku(int $storeId, string $sku): int
    {
        $orders = MarketplaceOrder::with('store', 'items')
            ->where('marketplace_store_id', $storeId)
            ->whereIn('status', [MarketplaceOrderStatus::Pending, MarketplaceOrderStatus::ToShip])
            ->whereHas('items', fn ($q) => $q->where('marketplace_sku', $sku))
            ->get();

        $orders->each(fn (MarketplaceOrder $order) => $this->schedule($order));

        return $orders->count();
    }

    /**
     * Alokasi stok ke semua pesanan Perlu Dikirim.
     *
     * @return array<int, array{state: string, short: array<int, float>}> order_id => status pemenuhan & kekurangan per varian
     */
    public function allocate(): array
    {
        if ($this->allocation !== null) {
            return $this->allocation;
        }

        $orders = MarketplaceOrder::with(['items', 'store:id,warehouse_id'])
            ->where('status', MarketplaceOrderStatus::ToShip)
            ->orderByRaw('CASE WHEN ship_by IS NULL THEN 1 ELSE 0 END')
            ->orderBy('ship_by')->orderBy('order_date')->orderBy('id')
            ->get();

        $variantIds = $orders->flatMap(fn ($o) => $o->items->pluck('product_variant_id'))->filter()->unique()->values();
        $available = WarehouseStock::where('stockable_type', (new ProductVariant)->getMorphClass())
            ->whereIn('stockable_id', $variantIds)
            ->get()
            ->mapWithKeys(fn (WarehouseStock $s) => [$s->warehouse_id.':'.$s->stockable_id => max(0, (float) $s->quantity)])
            ->all();

        $result = [];

        foreach ($orders as $order) {
            if ($order->hasUnmappedItems() || $order->items->isEmpty()) {
                $result[$order->id] = ['state' => self::UNMAPPED, 'short' => []];

                continue;
            }

            $need = [];
            foreach ($order->items as $item) {
                $need[$item->product_variant_id] = ($need[$item->product_variant_id] ?? 0) + $item->stockQuantity();
            }

            $short = [];
            foreach ($need as $variantId => $qty) {
                $have = $available[$order->store->warehouse_id.':'.$variantId] ?? 0;
                if ($qty > $have + 0.001) {
                    $short[$variantId] = round($qty - $have, 2);
                }
            }

            if (! $short) {
                foreach ($need as $variantId => $qty) {
                    $available[$order->store->warehouse_id.':'.$variantId] -= $qty;
                }
            }

            $result[$order->id] = ['state' => $short ? self::WAITING : self::READY, 'short' => $short];
        }

        return $this->allocation = $result;
    }

    /** @return list<int> */
    public function idsInState(string $state): array
    {
        return array_keys(array_filter($this->allocate(), fn ($row) => $row['state'] === $state));
    }

    public function stateOf(MarketplaceOrder $order): ?string
    {
        return $this->allocate()[$order->id]['state'] ?? null;
    }

    /**
     * Permintaan marketplace per varian (pesanan Perlu Dikirim yang sudah dipetakan).
     *
     * @return Collection<int, array{qty: float, preorder: float, waiting: float, orders: int, ship_by: Carbon|null}> variant_id => ...
     */
    public function demand(): Collection
    {
        $allocation = $this->allocate();

        $orders = MarketplaceOrder::with('items')
            ->where('status', MarketplaceOrderStatus::ToShip)
            ->get();

        $rows = [];
        foreach ($orders as $order) {
            $state = $allocation[$order->id]['state'] ?? self::WAITING;
            foreach ($order->items->whereNotNull('product_variant_id') as $item) {
                $row = $rows[$item->product_variant_id] ?? ['qty' => 0.0, 'preorder' => 0.0, 'waiting' => 0.0, 'orders' => [], 'ship_by' => null];
                $qty = $item->stockQuantity();
                $row['qty'] += $qty;
                $row['preorder'] += $order->is_preorder ? $qty : 0;
                $row['orders'][$order->id] = true;

                if ($state !== self::READY) {
                    $row['waiting'] += $qty;
                    if ($order->ship_by && (! $row['ship_by'] || $order->ship_by->lt($row['ship_by']))) {
                        $row['ship_by'] = $order->ship_by->copy();
                    }
                }
                $rows[$item->product_variant_id] = $row;
            }
        }

        return collect($rows)->map(fn ($row) => [
            'qty' => round($row['qty'], 2),
            'preorder' => round($row['preorder'], 2),
            'waiting' => round($row['waiting'], 2),
            'orders' => count($row['orders']),
            'ship_by' => $row['ship_by'],
        ]);
    }

    /** Pesanan Menunggu Produksi yang batas kirimnya ≤ URGENT_DAYS hari lagi (atau sudah lewat). */
    public function urgentWaitingCount(): int
    {
        $ids = $this->idsInState(self::WAITING);

        return $ids ? MarketplaceOrder::whereIn('id', $ids)->whereNotNull('ship_by')
            ->where('ship_by', '<=', today()->addDays(self::URGENT_DAYS)->toDateString().' 23:59:59')->count() : 0;
    }

    public function forget(): void
    {
        $this->allocation = null;
    }
}
