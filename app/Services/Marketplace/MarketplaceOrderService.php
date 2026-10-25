<?php

namespace App\Services\Marketplace;

use App\Enums\MarketplaceClaimStatus;
use App\Enums\MarketplaceOrderStatus;
use App\Enums\MarketplaceReturnCondition;
use App\Enums\StockLocation;
use App\Enums\StockMovementType;
use App\Models\ActivityLog;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceOrderItem;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceStore;
use App\Models\ProductVariant;
use App\Services\Accounting\AutoJournal;
use App\Services\StockService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Siklus pesanan TikTok Shop / Shopee:
 * Belum dibayar → Perlu dikirim (stok dialokasikan) → Dikirim (stok keluar dari gudang toko, penjualan & HPP dijurnal)
 * → Selesai. Batal sebelum dikirim tanpa efek stok/jurnal; setelah dikirim menjadi Retur diproses sampai
 * barang diterima gudang (stok kembali, penjualan dibalik).
 */
class MarketplaceOrderService
{
    public function __construct(private StockService $stock, private AutoJournal $journal) {}

    /**
     * Simpan / perbarui satu pesanan hasil impor atau API (format ternormalisasi, lihat {@see MarketplaceImporter}),
     * lalu sesuaikan statusnya. Impor ulang tidak menggandakan pesanan.
     *
     * @param  array<string, mixed>  $data
     *                                      Bila pesanan seharusnya sudah dikirim tetapi belum bisa (SKU belum dipetakan / stok kurang), pesanan tetap
     *                                      disimpan sebagai Perlu Dikirim dan alasannya dikembalikan sebagai peringatan.
     * @return array{result: string, warning: string|null} result = created | updated | unchanged
     */
    public function upsert(MarketplaceStore $store, array $data, string $source = 'import'): array
    {
        return DB::transaction(function () use ($store, $data, $source) {
            $order = MarketplaceOrder::where('marketplace_store_id', $store->id)->where('order_number', $data['order_number'])->lockForUpdate()->first();
            $target = MarketplaceOrderStatus::fromPlatform($data['status'] ?? null);
            $result = $order ? 'unchanged' : 'created';

            $header = array_filter([
                'platform_status' => $data['status'] ?? null,
                'order_date' => $data['order_date'] ?? null,
                'paid_at' => $data['paid_at'] ?? null,
                'buyer_username' => $data['buyer'] ?? null,
                'recipient_name' => $data['recipient'] ?? null,
                'city' => $data['city'] ?? null,
                'courier' => $data['courier'] ?? null,
                'tracking_number' => $data['tracking_number'] ?? null,
                'cancel_reason' => $data['cancel_reason'] ?? null,
            ], fn ($v) => $v !== null && $v !== '');

            if (! $order) {
                $order = MarketplaceOrder::create($header + [
                    'marketplace_store_id' => $store->id,
                    'order_number' => $data['order_number'],
                    'order_date' => $data['order_date'] ?? now(),
                    'status' => MarketplaceOrderStatus::ToShip,
                    'buyer_shipping_fee' => (float) ($data['shipping_fee'] ?? 0),
                    'source' => $source,
                ]);
                $this->replaceItems($order, $data['items'] ?? []);
                ActivityLog::record('created', "Pesanan {$store->platform->label()} {$order->order_number} masuk dari ".($source === 'api' ? 'API' : 'impor file'), $order);
            } else {
                $order->fill($header);
                if ($order->isDirty()) {
                    $order->save();
                    $result = 'updated';
                }

                // Baris barang hanya diganti selama belum dikirim
                if (! $order->isShipped() && $order->status !== MarketplaceOrderStatus::Cancelled && ! empty($data['items']) && $this->itemsChanged($order, $data['items'])) {
                    $this->replaceItems($order, $data['items']);
                    $result = 'updated';
                }
            }

            $before = $order->status;
            $warning = null;

            try {
                $this->applyStatus($order, $target, $data);
            } catch (ValidationException $e) {
                $warning = collect($e->errors())->flatten()->first();
                $order->refresh();
            }

            if ($result === 'unchanged' && $order->status !== $before) {
                $result = 'updated';
            }

            return ['result' => $result, 'warning' => $warning];
        });
    }

    /** Kirim pesanan: stok keluar dari gudang toko, penjualan diakui. */
    public function ship(MarketplaceOrder $order, \DateTimeInterface|string|null $date = null, MarketplaceOrderStatus $status = MarketplaceOrderStatus::Shipped): void
    {
        if ($order->isShipped()) {
            throw ValidationException::withMessages(['status' => "Pesanan {$order->order_number} sudah dikirim."]);
        }
        if ($order->status === MarketplaceOrderStatus::Cancelled) {
            throw ValidationException::withMessages(['status' => "Pesanan {$order->order_number} sudah dibatalkan."]);
        }

        $order->load('items', 'store');

        if ($order->items->isEmpty()) {
            throw ValidationException::withMessages(['items' => "Pesanan {$order->order_number} tidak memiliki barang."]);
        }

        if ($order->hasUnmappedItems()) {
            $skus = $order->items->whereNull('product_variant_id')->map(fn ($i) => $i->marketplace_sku ?: $i->product_name)->implode(', ');

            throw ValidationException::withMessages(['items' => "Pesanan {$order->order_number}: SKU belum dipetakan ke varian ERP ({$skus}). Atur di Marketplace → Mapping SKU."]);
        }

        DB::transaction(function () use ($order, $date, $status) {
            $date = Carbon::parse($date ?? today())->startOfDay();
            $rate = (float) $order->store->tax_rate;
            $totals = ['total' => 0, 'dpp' => 0, 'tax' => 0, 'cost' => 0];

            foreach ($order->items as $item) {
                $movement = $this->stock->move($item->variant, -$item->stockQuantity(), StockMovementType::MarketplaceSale, $order, $order->order_number,
                    "{$order->store->display_name}: {$item->label()}", $date, warehouse: $order->store->warehouse_id);

                $subtotal = (float) $item->subtotal;
                $dpp = $rate > 0 ? round($subtotal / (1 + $rate / 100), 2) : $subtotal;
                $unitCost = (float) $movement->unit_cost;
                $cost = round($item->stockQuantity() * $unitCost, 2);

                $item->update(['dpp' => $dpp, 'tax_amount' => round($subtotal - $dpp, 2), 'unit_cost' => $unitCost, 'cost' => $cost]);

                $totals['total'] += $subtotal;
                $totals['dpp'] += $dpp;
                $totals['tax'] += $subtotal - $dpp;
                $totals['cost'] += $cost;
            }

            $order->update([
                'status' => $status,
                'shipped_at' => $date,
                'completed_at' => $status === MarketplaceOrderStatus::Completed ? $date : $order->completed_at,
                'total' => round($totals['total'], 2),
                'dpp' => round($totals['dpp'], 2),
                'tax_amount' => round($totals['tax'], 2),
                'tax_rate' => $rate,
                'cost' => round($totals['cost'], 2),
            ]);

            $this->journal->marketplaceShipment($order);
            ActivityLog::record('shipped', "Pesanan dikirim dari {$order->store->warehouse->name}: ".rupiah($order->total), $order);
        });
    }

    public function complete(MarketplaceOrder $order, \DateTimeInterface|string|null $date = null): void
    {
        if ($order->status !== MarketplaceOrderStatus::Shipped) {
            throw ValidationException::withMessages(['status' => 'Hanya pesanan berstatus Dikirim yang bisa ditandai selesai.']);
        }

        $order->update(['status' => MarketplaceOrderStatus::Completed, 'completed_at' => Carbon::parse($date ?? today())]);
        ActivityLog::record('completed', 'Pesanan selesai (diterima pembeli)', $order);
    }

    /** Batal sebelum dikirim: tidak ada stok/jurnal yang perlu dibalik. */
    public function cancel(MarketplaceOrder $order, ?string $reason = null, \DateTimeInterface|string|null $date = null): void
    {
        if ($order->isShipped()) {
            throw ValidationException::withMessages(['status' => 'Pesanan yang sudah dikirim tidak bisa dibatalkan; proses sebagai retur.']);
        }

        $order->update(['status' => MarketplaceOrderStatus::Cancelled, 'cancelled_at' => Carbon::parse($date ?? today()), 'cancel_reason' => $reason ?: $order->cancel_reason]);
        ActivityLog::record('cancelled', 'Pesanan dibatalkan'.($reason ? ": {$reason}" : ''), $order);
    }

    /** Pembeli mengajukan pengembalian / paket gagal kirim: menunggu barang diterima gudang. */
    public function requestReturn(MarketplaceOrder $order, ?string $reason = null): void
    {
        if (! in_array($order->status, [MarketplaceOrderStatus::Shipped, MarketplaceOrderStatus::Completed], true)) {
            throw ValidationException::withMessages(['status' => 'Retur hanya untuk pesanan yang sudah dikirim / selesai.']);
        }

        $order->update(['status' => MarketplaceOrderStatus::ReturnRequested, 'return_reason' => $reason ?: $order->return_reason]);
        ActivityLog::record('return_requested', 'Retur diproses'.($reason ? ": {$reason}" : ''), $order);
    }

    /** Pengajuan retur ditolak / dibatalkan: pesanan kembali selesai. */
    public function rejectReturn(MarketplaceOrder $order, ?string $reason = null): void
    {
        if ($order->status !== MarketplaceOrderStatus::ReturnRequested) {
            throw ValidationException::withMessages(['status' => 'Pesanan ini tidak sedang dalam proses retur.']);
        }

        $order->update(['status' => $order->completed_at ? MarketplaceOrderStatus::Completed : MarketplaceOrderStatus::Shipped]);
        ActivityLog::record('return_rejected', 'Retur ditolak / batal'.($reason ? ": {$reason}" : ''), $order);
    }

    /**
     * Terima retur (satu kali per pesanan). Per baris: qty direfund (penjualan dibalik), qty barang KITA yang kembali
     * ke gudang toko (HPP dibalik), dan dari yang kembali berapa yang cacat (masuk stok cacat).
     * Qty direfund yang tidak kembali (paket tidak sampai, isi kosong, barang tertukar / bukan barang kita) dicatat
     * sebagai kerugian retur marketplace dan bisa diajukan banding ({@see openClaim()}).
     * $refundAmount mengganti nilai refund per qty bila platform merefund sebagian (mis. kompensasi barang kurang).
     *
     * @param  array<int, array{returned?: float|string|null, restocked?: float|string|null, defect?: float|string|null}>  $lines  item_id => qty
     */
    public function receiveReturn(MarketplaceOrder $order, array $lines, \DateTimeInterface|string|null $date = null, ?string $reason = null,
        MarketplaceReturnCondition|string|null $condition = null, float|string|null $refundAmount = null): void
    {
        $condition = is_string($condition) ? MarketplaceReturnCondition::from($condition) : $condition;
        if (! in_array($order->status, [MarketplaceOrderStatus::ReturnRequested, MarketplaceOrderStatus::Shipped, MarketplaceOrderStatus::Completed], true) || $order->returned_at) {
            throw ValidationException::withMessages(['status' => 'Retur hanya bisa dicatat sekali untuk pesanan yang sudah dikirim.']);
        }

        $order->load('items.variant', 'store.warehouse');
        $date = Carbon::parse($date ?? today())->startOfDay();

        if ($date->lt($order->shipped_at)) {
            throw ValidationException::withMessages(['date' => 'Tanggal retur tidak boleh sebelum tanggal kirim ('.tanggal($order->shipped_at).').']);
        }

        $rows = [];
        foreach ($order->items as $item) {
            $line = $lines[$item->id] ?? [];
            $returned = round((float) ($line['returned'] ?? 0), 2);
            $restocked = round((float) ($line['restocked'] ?? 0), 2);
            $defect = round((float) ($line['defect'] ?? 0), 2);
            $qty = (float) $item->quantity;
            $name = $item->label();

            if ($returned < 0 || $restocked < 0 || $defect < 0 || $returned > $qty || $restocked > $qty) {
                throw ValidationException::withMessages(["lines.{$item->id}" => "{$name}: jumlah retur / barang kembali harus 0 s.d. ".qty($qty).'.']);
            }
            if ($defect > $restocked) {
                throw ValidationException::withMessages(["lines.{$item->id}" => "{$name}: jumlah cacat tidak boleh melebihi barang yang kembali."]);
            }

            $rows[$item->id] = compact('item', 'returned', 'restocked', 'defect');
        }

        $refundAmount = $refundAmount === null || $refundAmount === '' ? null : round((float) $refundAmount, 2);

        if (collect($rows)->sum(fn ($r) => $r['returned'] + $r['restocked']) <= 0 && ! $refundAmount) {
            throw ValidationException::withMessages(['lines' => 'Isi jumlah yang direfund atau barang yang kembali.']);
        }
        if ($refundAmount !== null && ($refundAmount < 0 || $refundAmount > (float) $order->total)) {
            throw ValidationException::withMessages(['refund_amount' => 'Nilai refund harus 0 s.d. '.rupiah($order->total).'.']);
        }

        DB::transaction(function () use ($order, $rows, $date, $reason, $condition, $refundAmount) {
            $totals = ['total' => 0, 'dpp' => 0, 'tax' => 0, 'cost' => 0, 'lost' => 0];

            foreach ($rows as ['item' => $item, 'returned' => $returned, 'restocked' => $restocked, 'defect' => $defect]) {
                $ratio = (float) $item->quantity > 0 ? $returned / (float) $item->quantity : 0;
                $totals['total'] += round((float) $item->subtotal * $ratio, 2);
                $totals['dpp'] += round((float) $item->dpp * $ratio, 2);
                $totals['tax'] += round((float) $item->tax_amount * $ratio, 2);

                $units = (float) $item->units_per_item;
                $good = round(($restocked - $defect) * $units, 2);
                $bad = round($defect * $units, 2);
                $note = "Retur {$order->store->display_name}";

                if ($good > 0) {
                    $this->stock->move($item->variant, $good, StockMovementType::MarketplaceReturn, $order, $order->order_number, $note, $date,
                        unitCost: (float) $item->unit_cost, warehouse: $order->store->warehouse_id);
                }
                if ($bad > 0) {
                    $this->stock->move($item->variant, $bad, StockMovementType::MarketplaceReturn, $order, $order->order_number, "{$note} (cacat)", $date,
                        unitCost: (float) $item->unit_cost, location: StockLocation::Defect, warehouse: $order->store->warehouse_id);
                }

                $totals['cost'] += round($restocked * $units * (float) $item->unit_cost, 2);
                // Direfund tapi barang kita tidak kembali (hilang, kosong, tertukar) → kerugian retur
                $totals['lost'] += round(max(0, $returned - $restocked) * $units * (float) $item->unit_cost, 2);
                $item->update(['returned_quantity' => $returned, 'restocked_quantity' => $restocked, 'defect_quantity' => $defect]);
            }

            if ($refundAmount !== null) {
                // Refund sebagian sesuai nominal dari platform, DPP & PPN proporsional terhadap total pesanan
                $ratio = (float) $order->total > 0 ? $refundAmount / (float) $order->total : 0;
                $totals['total'] = $refundAmount;
                $totals['dpp'] = round((float) $order->dpp * $ratio, 2);
                $totals['tax'] = round($refundAmount - $totals['dpp'], 2);
            }

            $order->update([
                'status' => MarketplaceOrderStatus::Returned,
                'returned_at' => $date,
                'return_reason' => $reason ?: $order->return_reason,
                'return_condition' => $condition ?? ($totals['lost'] > 0 ? MarketplaceReturnCondition::NotReceived : MarketplaceReturnCondition::Good),
                'returned_total' => round($totals['total'], 2),
                'returned_dpp' => round($totals['dpp'], 2),
                'returned_tax' => round($totals['tax'], 2),
                'returned_cost' => round($totals['cost'], 2),
                'returned_lost_cost' => round($totals['lost'], 2),
            ]);

            $this->journal->marketplaceReturn($order);
            ActivityLog::record('returned', "Retur diterima ({$order->return_condition->label()}): refund ".rupiah($order->returned_total).', barang kembali senilai HPP '.rupiah($order->returned_cost)
                .((float) $order->returned_lost_cost ? ', kerugian barang tidak kembali '.rupiah($order->returned_lost_cost) : ''), $order);
        });
    }

    /** Ajukan banding / klaim ke platform (bukti: video unboxing, foto paket, resi retur). */
    public function openClaim(MarketplaceOrder $order, string $notes): void
    {
        if (! $order->canOpenClaim()) {
            throw ValidationException::withMessages(['claim' => 'Banding hanya untuk retur bermasalah yang sudah dicatat dan belum pernah diajukan.']);
        }

        $order->update(['claim_status' => MarketplaceClaimStatus::Open, 'claim_notes' => $notes]);
        ActivityLog::record('claim_opened', "Banding diajukan ke {$order->store->platform->label()}: {$notes}", $order);
    }

    /**
     * Hasil banding. Diterima: kompensasi platform dicatat sebagai piutang marketplace (mengurangi kerugian retur)
     * dan akan tertutup saat kompensasi ikut dicairkan. Ditolak: kerugian tetap.
     */
    public function resolveClaim(MarketplaceOrder $order, bool $won, float $amount = 0, \DateTimeInterface|string|null $date = null, ?string $notes = null): void
    {
        if ($order->claim_status !== MarketplaceClaimStatus::Open) {
            throw ValidationException::withMessages(['claim' => 'Tidak ada banding yang sedang diproses untuk pesanan ini.']);
        }
        if ($won && $amount <= 0) {
            throw ValidationException::withMessages(['claim_amount' => 'Isi nilai kompensasi yang disetujui platform.']);
        }

        DB::transaction(function () use ($order, $won, $amount, $date, $notes) {
            $order->update([
                'claim_status' => $won ? MarketplaceClaimStatus::Won : MarketplaceClaimStatus::Lost,
                'claim_amount' => $won ? round($amount, 2) : 0,
                'claim_resolved_at' => Carbon::parse($date ?? today()),
                'claim_notes' => trim($order->claim_notes.($notes ? "\n".$notes : '')),
            ]);

            if ($won) {
                $this->journal->marketplaceClaim($order);
            }

            ActivityLog::record('claim_resolved', $won ? 'Banding diterima, kompensasi '.rupiah($amount) : 'Banding ditolak'.($notes ? ": {$notes}" : ''), $order);
        });
    }

    /** Terapkan pemetaan SKU ke baris pesanan yang belum dikirim. */
    public function applyMapping(MarketplaceProduct $mapping): int
    {
        return MarketplaceOrderItem::where('marketplace_sku', $mapping->marketplace_sku)
            ->whereHas('order', fn ($q) => $q->where('marketplace_store_id', $mapping->marketplace_store_id)->whereNull('shipped_at'))
            ->update(['product_variant_id' => $mapping->product_variant_id, 'units_per_item' => $mapping->units_per_item]);
    }

    /**
     * Cari pemetaan SKU toko; bila belum ada tetapi SKU sama dengan SKU varian ERP, pemetaan dibuat otomatis.
     */
    public static function resolveSku(MarketplaceStore $store, ?string $sku): ?MarketplaceProduct
    {
        $sku = trim((string) $sku);
        if ($sku === '') {
            return null;
        }

        $mapping = MarketplaceProduct::where('marketplace_store_id', $store->id)->where('marketplace_sku', $sku)->first();

        if (! $mapping && ($variant = ProductVariant::where('sku', $sku)->first())) {
            $mapping = MarketplaceProduct::create([
                'marketplace_store_id' => $store->id,
                'marketplace_sku' => $sku,
                'product_variant_id' => $variant->id,
                'units_per_item' => 1,
            ]);
        }

        return $mapping;
    }

    private function applyStatus(MarketplaceOrder $order, MarketplaceOrderStatus $target, array $data): void
    {
        $shippedDate = $data['shipped_at'] ?? $data['completed_at'] ?? $data['paid_at'] ?? $data['order_date'] ?? today();

        switch ($target) {
            case MarketplaceOrderStatus::Pending:
            case MarketplaceOrderStatus::ToShip:
                if (! $order->isShipped() && $order->status !== $target) {
                    $order->update(['status' => $target]);
                }
                break;

            case MarketplaceOrderStatus::Shipped:
            case MarketplaceOrderStatus::Completed:
                if (! $order->isShipped()) {
                    if ($order->status === MarketplaceOrderStatus::Cancelled) {
                        $order->update(['status' => MarketplaceOrderStatus::ToShip, 'cancelled_at' => null]);
                    }
                    $this->ship($order, $shippedDate, $target);
                } elseif ($order->status === MarketplaceOrderStatus::Shipped && $target === MarketplaceOrderStatus::Completed) {
                    $this->complete($order, $data['completed_at'] ?? today());
                }
                break;

            case MarketplaceOrderStatus::Cancelled:
                if (! $order->isShipped()) {
                    if ($order->status !== MarketplaceOrderStatus::Cancelled) {
                        $this->cancel($order, $data['cancel_reason'] ?? null, $data['cancelled_at'] ?? today());
                    }
                } elseif (in_array($order->status, [MarketplaceOrderStatus::Shipped, MarketplaceOrderStatus::Completed], true)) {
                    // Batal setelah dikirim = paket gagal kirim / dikembalikan kurir
                    $this->requestReturn($order, 'Dibatalkan setelah dikirim'.(! empty($data['cancel_reason']) ? ': '.$data['cancel_reason'] : ' (gagal kirim)'));
                }
                break;

            case MarketplaceOrderStatus::ReturnRequested:
            case MarketplaceOrderStatus::Returned:
                if (! $order->isShipped() && $order->status !== MarketplaceOrderStatus::Cancelled) {
                    $this->ship($order, $shippedDate);
                    $order->refresh();
                }
                if (in_array($order->status, [MarketplaceOrderStatus::Shipped, MarketplaceOrderStatus::Completed], true)) {
                    $this->requestReturn($order, $data['return_reason'] ?? $data['status'] ?? null);
                }
                break;
        }
    }

    /** @param  list<array<string, mixed>>  $items */
    private function replaceItems(MarketplaceOrder $order, array $items): void
    {
        $order->items()->delete();
        $store = $order->store;

        foreach ($items as $line) {
            $qty = round((float) ($line['quantity'] ?? 0), 2);
            if ($qty <= 0) {
                continue;
            }

            $subtotal = round((float) ($line['subtotal'] ?? 0), 2);
            $price = round((float) ($line['unit_price'] ?? 0), 2);
            if ($subtotal <= 0 && $price > 0) {
                $subtotal = round($price * $qty, 2);
            }
            if ($price <= 0 && $subtotal > 0) {
                $price = round($subtotal / $qty, 2);
            }

            $mapping = self::resolveSku($store, $line['sku'] ?? null);

            $order->items()->create([
                'marketplace_sku' => $line['sku'] ?? null,
                'product_name' => isset($line['name']) ? mb_substr((string) $line['name'], 0, 255) : null,
                'variation' => isset($line['variation']) ? mb_substr((string) $line['variation'], 0, 255) : null,
                'product_variant_id' => $mapping?->product_variant_id,
                'units_per_item' => $mapping?->units_per_item ?? 1,
                'quantity' => $qty,
                'unit_price' => $price,
                'subtotal' => $subtotal,
            ]);
        }

        $order->update(['total' => round($order->items()->sum('subtotal'), 2)]);
        $order->unsetRelation('items');
    }

    /** @param  list<array<string, mixed>>  $items */
    private function itemsChanged(MarketplaceOrder $order, array $items): bool
    {
        $current = $order->items()->get()->map(fn ($i) => [(string) $i->marketplace_sku, round((float) $i->quantity, 2), round((float) $i->subtotal, 2)])->sort()->values()->all();
        $incoming = collect($items)->filter(fn ($l) => (float) ($l['quantity'] ?? 0) > 0)->map(function ($l) {
            $qty = round((float) $l['quantity'], 2);
            $subtotal = round((float) ($l['subtotal'] ?? 0), 2) ?: round((float) ($l['unit_price'] ?? 0) * $qty, 2);

            return [(string) ($l['sku'] ?? ''), $qty, $subtotal];
        })->sort()->values()->all();

        return $current != $incoming;
    }
}
