<?php

namespace App\Services;

use App\Enums\StockMovementType;
use App\Models\ActivityLog;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseService
{
    public function __construct(private StockService $stock) {}

    /**
     * @param  array<int, float>  $quantities  purchase_order_item_id => qty diterima
     */
    public function receive(PurchaseOrder $po, array $quantities, array $data): GoodsReceipt
    {
        if (! $po->canReceive()) {
            throw ValidationException::withMessages(['status' => 'PO ini tidak dapat diterima pada status saat ini.']);
        }

        $quantities = array_filter(array_map('floatval', $quantities), fn ($q) => $q > 0);

        if (empty($quantities)) {
            throw ValidationException::withMessages(['items' => 'Isi minimal satu jumlah barang yang diterima.']);
        }

        return DB::transaction(function () use ($po, $quantities, $data) {
            $date = Carbon::parse($data['receipt_date']);

            $receipt = $po->receipts()->create([
                'number' => DocumentNumber::generate(GoodsReceipt::class, 'GR', $date),
                'receipt_date' => $date,
                'delivery_note' => $data['delivery_note'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $items = $po->items()->with('material')->lockForUpdate()->get()->keyBy('id');

            foreach ($quantities as $itemId => $qty) {
                $item = $items->get($itemId);

                if (! $item) {
                    continue;
                }

                if ($qty > $item->outstandingQuantity() + 0.0001) {
                    throw ValidationException::withMessages([
                        "items.$itemId" => "Jumlah diterima untuk {$item->material->name} melebihi sisa pesanan (".qty($item->outstandingQuantity()).').',
                    ]);
                }

                $receipt->items()->create([
                    'purchase_order_item_id' => $item->id,
                    'material_id' => $item->material_id,
                    'quantity' => $qty,
                ]);

                $item->increment('received_quantity', $qty);

                $this->stock->move(
                    $item->material, $qty, StockMovementType::PurchaseReceipt,
                    $receipt, $receipt->number, 'Penerimaan '.$po->number, $date,
                );
            }

            $po->refreshReceiptStatus();

            ActivityLog::record('received', "Menerima barang {$receipt->number}", $po);

            return $receipt;
        });
    }
}
