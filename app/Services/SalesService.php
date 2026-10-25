<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Models\ActivityLog;
use App\Models\Delivery;
use App\Models\SalesOrder;
use App\Models\Warehouse;
use App\Services\Accounting\AutoJournal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesService
{
    public function __construct(private StockService $stock, private AutoJournal $journal) {}

    /**
     * Buat surat jalan (boleh sebagian) dan kurangi stok barang jadi.
     *
     * @param  array<int, float>  $quantities  sales_order_item_id => qty dikirim
     */
    public function deliver(SalesOrder $order, array $quantities, array $data): Delivery
    {
        if (! $order->canDeliver()) {
            throw ValidationException::withMessages(['status' => 'Hanya SO berstatus Dikonfirmasi / Terkirim Sebagian yang dapat dikirim.']);
        }

        $quantities = array_filter(array_map('floatval', $quantities), fn ($q) => $q > 0);

        if (empty($quantities)) {
            throw ValidationException::withMessages(['items' => 'Isi minimal satu jumlah barang yang dikirim.']);
        }

        return DB::transaction(function () use ($order, $quantities, $data) {
            $date = Carbon::parse($data['delivery_date']);

            $delivery = $order->deliveries()->create([
                'number' => DocumentNumber::generate(Delivery::class, 'SJ', $date),
                'delivery_date' => $date,
                'vehicle_number' => $data['vehicle_number'] ?? null,
                'driver_name' => $data['driver_name'] ?? null,
                'shipping_address' => $data['shipping_address'] ?? $order->customer->address,
                'warehouse_id' => Warehouse::resolveId($data['warehouse_id'] ?? null),
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $items = $order->items()->with('variant.product')->lockForUpdate()->get()->keyBy('id');

            foreach ($quantities as $itemId => $qty) {
                $item = $items->get($itemId);

                if (! $item) {
                    continue;
                }

                if ($qty > $item->outstandingQuantity() + 0.0001) {
                    throw ValidationException::withMessages([
                        "items.$itemId" => "Jumlah kirim {$item->variant->display_name} melebihi sisa pesanan (".qty($item->outstandingQuantity()).').',
                    ]);
                }

                $item->increment('delivered_quantity', $qty);

                // Barang keluar dinilai dengan harga pokok rata-rata varian saat itu (HPP penjualan)
                $movement = $this->stock->move(
                    $item->variant, -1 * $qty, StockMovementType::SalesDelivery,
                    $delivery, $delivery->number, 'Pengiriman '.$order->number.' ke '.$order->customer->name, $date, warehouse: $delivery->warehouse_id,
                );
                $unitCost = round((float) $movement->unit_cost, 2);

                $delivery->items()->create([
                    'sales_order_item_id' => $item->id,
                    'product_variant_id' => $item->product_variant_id,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'cost' => round($unitCost * $qty, 2),
                ]);
            }

            $order->refreshDeliveryStatus();
            $this->journal->delivery($delivery);

            ActivityLog::record('delivered', "Membuat surat jalan {$delivery->number}", $order);

            return $delivery;
        });
    }
}
