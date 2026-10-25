<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Models\ActivityLog;
use App\Models\Bill;
use App\Models\PurchaseOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tagihan supplier (hutang). Dicocokkan dengan PO dan penerimaan barang (3-way match):
 * jumlah yang ditagih tidak boleh melebihi barang yang sudah diterima dan belum ditagih.
 */
class BillService
{
    /**
     * @param  array<int, array{quantity?: float|string|null, price?: float|string|null}>  $lines  purchase_order_item_id => qty & harga
     * @param  array{bill_date: string, due_date?: string|null, supplier_invoice_number?: string|null, discount?: float|string|null, tax_rate?: float|string|null, notes?: string|null}  $data
     */
    public function createFromPurchaseOrder(PurchaseOrder $po, array $lines, array $data): Bill
    {
        if (in_array($po->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::PendingApproval, PurchaseOrderStatus::Cancelled], true)) {
            throw ValidationException::withMessages(['status' => 'PO draft atau dibatalkan tidak dapat dibuatkan tagihan.']);
        }

        $lines = array_filter($lines, fn ($l) => (float) ($l['quantity'] ?? 0) > 0);

        if (empty($lines)) {
            throw ValidationException::withMessages(['items' => 'Isi minimal satu jumlah barang yang ditagih.']);
        }

        $supplierInvoice = trim((string) ($data['supplier_invoice_number'] ?? '')) ?: null;

        if ($supplierInvoice && Bill::where('supplier_id', $po->supplier_id)->where('supplier_invoice_number', $supplierInvoice)
            ->where('status', '!=', InvoiceStatus::Cancelled)->exists()) {
            throw ValidationException::withMessages(['supplier_invoice_number' => "No. faktur supplier {$supplierInvoice} sudah pernah dicatat."]);
        }

        return DB::transaction(function () use ($po, $lines, $data, $supplierInvoice) {
            $date = Carbon::parse($data['bill_date']);
            $items = $po->items()->with('material')->lockForUpdate()->get()->keyBy('id');
            $rows = [];

            foreach ($lines as $itemId => $line) {
                $item = $items->get($itemId);

                if (! $item) {
                    continue;
                }

                $qty = (float) $line['quantity'];
                $price = isset($line['price']) && $line['price'] !== '' ? (float) $line['price'] : (float) $item->price;

                if ($qty > $item->unbilledQuantity() + 0.0001) {
                    throw ValidationException::withMessages([
                        "items.$itemId.quantity" => "Jumlah tagih {$item->material->name} melebihi barang diterima yang belum ditagih (".qty($item->unbilledQuantity()).').',
                    ]);
                }

                $rows[] = [
                    'purchase_order_item_id' => $item->id,
                    'material_id' => $item->material_id,
                    'quantity' => $qty,
                    'price' => $price,
                    'subtotal' => round($qty * $price, 2),
                ];

                $item->billed_quantity = (float) $item->billed_quantity + $qty;
                $item->save();
            }

            if (empty($rows)) {
                throw ValidationException::withMessages(['items' => 'Item yang dipilih tidak ditemukan pada PO ini.']);
            }

            $subtotal = round(array_sum(array_column($rows, 'subtotal')), 2);
            $discount = round(min(max((float) ($data['discount'] ?? 0), 0), $subtotal), 2);
            $taxRate = (float) ($data['tax_rate'] ?? 0);
            $taxAmount = round(($subtotal - $discount) * $taxRate / 100);

            $bill = Bill::create([
                'number' => DocumentNumber::generate(Bill::class, 'BILL', $date),
                'supplier_invoice_number' => $supplierInvoice,
                'supplier_id' => $po->supplier_id,
                'purchase_order_id' => $po->id,
                'bill_date' => $date,
                'due_date' => ! empty($data['due_date']) ? Carbon::parse($data['due_date']) : $date->copy()->addDays($po->supplier->payment_terms),
                'status' => InvoiceStatus::Unpaid,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'total' => $subtotal - $discount + $taxAmount,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);
            $bill->items()->createMany($rows);

            ActivityLog::record('created', "Mencatat tagihan {$bill->number} dari PO {$po->number}", $bill);
            ActivityLog::record('billed', "Mencatat tagihan supplier {$bill->number}", $po);

            return $bill;
        });
    }

    public function cancel(Bill $bill, ?string $reason = null): void
    {
        if (! $bill->canCancel()) {
            throw ValidationException::withMessages(['status' => 'Tagihan yang sudah ada pembayaran tidak dapat dibatalkan. Batalkan pembayarannya terlebih dulu.']);
        }

        DB::transaction(function () use ($bill, $reason) {
            foreach ($bill->items as $line) {
                $poItem = $line->purchaseOrderItem()->lockForUpdate()->first();
                $poItem->billed_quantity = max(0, (float) $poItem->billed_quantity - (float) $line->quantity);
                $poItem->save();
            }

            $bill->update(['status' => InvoiceStatus::Cancelled]);

            ActivityLog::record('cancelled', 'Membatalkan tagihan'.($reason ? ": {$reason}" : ''), $bill);
            ActivityLog::record('bill_cancelled', "Tagihan {$bill->number} dibatalkan", $bill->purchaseOrder);
        });
    }
}
