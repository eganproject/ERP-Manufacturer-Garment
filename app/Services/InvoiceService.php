<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\SalesOrderStatus;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\SalesOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Invoice penjualan (piutang). Yang ditagih hanya barang yang sudah dikirim (surat jalan)
 * dan belum pernah ditagihkan, sehingga satu SO bisa ditagih bertahap tanpa dobel.
 */
class InvoiceService
{
    /**
     * @param  array<int, float>  $quantities  sales_order_item_id => qty ditagih
     * @param  array{invoice_date: string, due_date?: string|null, tax_rate?: float|string|null, notes?: string|null}  $data
     */
    public function createFromSalesOrder(SalesOrder $order, array $quantities, array $data): Invoice
    {
        if (in_array($order->status, [SalesOrderStatus::Draft, SalesOrderStatus::Cancelled], true)) {
            throw ValidationException::withMessages(['status' => 'SO draft atau dibatalkan tidak dapat dibuatkan invoice.']);
        }

        $quantities = array_filter(array_map('floatval', $quantities), fn ($q) => $q > 0);

        if (empty($quantities)) {
            throw ValidationException::withMessages(['items' => 'Isi minimal satu jumlah barang yang ditagih.']);
        }

        return DB::transaction(function () use ($order, $quantities, $data) {
            $date = Carbon::parse($data['invoice_date']);
            $items = $order->items()->with('variant.product')->lockForUpdate()->get()->keyBy('id');
            $lines = [];

            foreach ($quantities as $itemId => $qty) {
                $item = $items->get($itemId);

                if (! $item) {
                    continue;
                }

                if ($qty > $item->uninvoicedQuantity() + 0.0001) {
                    throw ValidationException::withMessages([
                        "items.$itemId" => "Jumlah tagih {$item->variant->display_name} melebihi barang terkirim yang belum ditagih (".qty($item->uninvoicedQuantity()).').',
                    ]);
                }

                $unitCost = $item->deliveredUnitCost();

                $lines[] = [
                    'sales_order_item_id' => $item->id,
                    'product_variant_id' => $item->product_variant_id,
                    'quantity' => $qty,
                    'price' => $item->price,
                    'subtotal' => round($qty * (float) $item->price, 2),
                    'unit_cost' => $unitCost,
                    'cost' => round($qty * $unitCost, 2),
                ];

                $item->invoiced_quantity = (float) $item->invoiced_quantity + $qty;
                $item->save();
            }

            if (empty($lines)) {
                throw ValidationException::withMessages(['items' => 'Item yang dipilih tidak ditemukan pada SO ini.']);
            }

            $subtotal = round(array_sum(array_column($lines, 'subtotal')), 2);
            $discount = $this->allocateDiscount($order, $items, $subtotal);
            $taxRate = (float) ($data['tax_rate'] ?? 0);
            $taxAmount = round(($subtotal - $discount) * $taxRate / 100);

            $invoice = Invoice::create([
                'number' => DocumentNumber::generate(Invoice::class, 'INV', $date),
                'customer_id' => $order->customer_id,
                'sales_order_id' => $order->id,
                'invoice_date' => $date,
                'due_date' => ! empty($data['due_date']) ? Carbon::parse($data['due_date']) : $date->copy()->addDays($order->customer->payment_terms),
                'status' => InvoiceStatus::Unpaid,
                'subtotal' => $subtotal,
                'discount' => $discount,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'total' => $subtotal - $discount + $taxAmount,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);
            $invoice->items()->createMany($lines);

            ActivityLog::record('created', "Membuat invoice {$invoice->number} dari SO {$order->number}", $invoice);
            ActivityLog::record('invoiced', "Membuat invoice {$invoice->number}", $order);

            return $invoice;
        });
    }

    public function cancel(Invoice $invoice, ?string $reason = null): void
    {
        if (! $invoice->canCancel()) {
            throw ValidationException::withMessages(['status' => 'Invoice yang sudah ada pembayaran tidak dapat dibatalkan. Batalkan pembayarannya terlebih dulu.']);
        }

        DB::transaction(function () use ($invoice, $reason) {
            foreach ($invoice->items()->with('salesOrderItem')->get() as $line) {
                $soItem = $line->salesOrderItem()->lockForUpdate()->first();
                $soItem->invoiced_quantity = max(0, (float) $soItem->invoiced_quantity - (float) $line->quantity);
                $soItem->save();
            }

            $invoice->update(['status' => InvoiceStatus::Cancelled]);

            ActivityLog::record('cancelled', 'Membatalkan invoice'.($reason ? ": {$reason}" : ''), $invoice);
            ActivityLog::record('invoice_cancelled', "Invoice {$invoice->number} dibatalkan", $invoice->salesOrder);
        });
    }

    /**
     * Diskon SO dibagi proporsional ke tiap invoice. Invoice yang menutup seluruh SO
     * mengambil sisa diskon agar total semua invoice sama persis dengan total SO.
     */
    private function allocateDiscount(SalesOrder $order, $items, float $subtotal): float
    {
        $soDiscount = (float) $order->discount;

        if ($soDiscount <= 0 || (float) $order->subtotal <= 0) {
            return 0;
        }

        $fullyInvoiced = $items->every(fn ($i) => (float) $i->invoiced_quantity + 0.0001 >= (float) $i->quantity);

        if ($fullyInvoiced) {
            $used = (float) $order->invoices()->where('status', '!=', InvoiceStatus::Cancelled)->sum('discount');
            $discount = $soDiscount - $used;
        } else {
            $discount = round($soDiscount * $subtotal / (float) $order->subtotal);
        }

        return round(min(max($discount, 0), $subtotal), 2);
    }
}
