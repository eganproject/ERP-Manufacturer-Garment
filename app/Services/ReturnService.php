<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\ReturnStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\StockMovementType;
use App\Models\ActivityLog;
use App\Models\Bill;
use App\Models\BillItem;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Retur penjualan (RJ) dan retur pembelian (RB).
 *
 * - Retur penjualan: barang masuk lagi ke stok dengan HPP rata-rata saat dikirim.
 * - Retur pembelian: bahan keluar dari stok dengan harga pokok rata-rata saat itu.
 * - Retur atas barang yang belum ditagih hanya mengurangi jumlah yang bisa ditagih.
 * - Retur atas barang yang sudah ditagih menjadi nota kredit/debit (DPP + PPN) yang otomatis
 *   dipakai mengurangi invoice/tagihan terbuka dari SO/PO yang sama; sisanya bisa dipakai
 *   untuk dokumen lain pihak yang sama atau dikembalikan sebagai uang.
 */
class ReturnService
{
    public function __construct(private StockService $stock) {}

    /**
     * @param  array<int, float|string|null>  $quantities  sales_order_item_id => qty diretur
     * @param  array{return_date: string, reason: string, notes?: string|null}  $data
     */
    public function createSalesReturn(SalesOrder $order, array $quantities, array $data): SalesReturn
    {
        if (in_array($order->status, [SalesOrderStatus::Draft, SalesOrderStatus::Cancelled], true)) {
            throw ValidationException::withMessages(['status' => 'SO draft atau dibatalkan tidak dapat diretur.']);
        }

        $quantities = $this->filterQuantities($quantities);

        return DB::transaction(function () use ($order, $quantities, $data) {
            $date = Carbon::parse($data['return_date']);
            $items = $order->items()->with('variant.product')->lockForUpdate()->get()->keyBy('id');
            // Diskon SO dibagi proporsional, sama seperti pada invoice
            $factor = (float) $order->subtotal > 0 ? 1 - (float) $order->discount / (float) $order->subtotal : 1;

            $return = SalesReturn::create([
                'number' => DocumentNumber::generate(SalesReturn::class, 'RJ', $date),
                'customer_id' => $order->customer_id,
                'sales_order_id' => $order->id,
                'return_date' => $date,
                'status' => ReturnStatus::Posted,
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            foreach ($quantities as $itemId => $qty) {
                $item = $items->get($itemId);

                if (! $item) {
                    continue;
                }

                if ($qty > $item->returnableQuantity() + 0.0001) {
                    throw ValidationException::withMessages([
                        "items.$itemId" => "Jumlah retur {$item->variant->display_name} melebihi barang terkirim yang belum diretur (".qty($item->returnableQuantity()).').',
                    ]);
                }

                // Barang yang belum ditagih diretur lebih dulu; sisanya mengurangi invoice (nota kredit)
                $billedQty = round(max(0, $qty - $item->uninvoicedQuantity()), 2);
                $unitCost = $item->deliveredUnitCost();

                $item->returned_quantity = (float) $item->returned_quantity + $qty;
                $item->returned_invoiced_quantity = (float) $item->returned_invoiced_quantity + $billedQty;
                $item->save();

                $this->stock->move(
                    $item->variant, $qty, StockMovementType::SalesReturn,
                    $return, $return->number, 'Retur '.$order->number.' dari '.$order->customer->name, $date,
                    unitCost: $unitCost > 0 ? $unitCost : null,
                );

                $return->items()->create([
                    'sales_order_item_id' => $item->id,
                    'product_variant_id' => $item->product_variant_id,
                    'quantity' => $qty,
                    'billed_quantity' => $billedQty,
                    'price' => $item->price,
                    'subtotal' => round($qty * (float) $item->price, 2),
                    'amount' => round($billedQty * (float) $item->price * $factor, 2),
                    'unit_cost' => $unitCost,
                    'cost' => round($qty * $unitCost, 2),
                    'billed_cost' => round($billedQty * $unitCost, 2),
                ]);
            }

            $taxRate = (float) ($order->invoices()->where('status', '!=', InvoiceStatus::Cancelled)->latest('invoice_date')->latest('id')->value('tax_rate') ?? 0);
            $this->finalizeNote($return, $taxRate);

            $this->autoApply($return, $order->invoices()->open()->orderBy('invoice_date')->orderBy('id')->get(), $date);

            ActivityLog::record('created', "Mencatat retur penjualan {$return->number} dari SO {$order->number}", $return);
            ActivityLog::record('returned', "Retur barang {$return->number}", $order);

            return $return;
        });
    }

    /**
     * @param  array<int, float|string|null>  $quantities  purchase_order_item_id => qty diretur
     * @param  array{return_date: string, reason: string, notes?: string|null}  $data
     */
    public function createPurchaseReturn(PurchaseOrder $po, array $quantities, array $data): PurchaseReturn
    {
        if (in_array($po->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::PendingApproval, PurchaseOrderStatus::Cancelled], true)) {
            throw ValidationException::withMessages(['status' => 'PO draft atau dibatalkan tidak dapat diretur.']);
        }

        $quantities = $this->filterQuantities($quantities);

        return DB::transaction(function () use ($po, $quantities, $data) {
            $date = Carbon::parse($data['return_date']);
            $items = $po->items()->with('material')->lockForUpdate()->get()->keyBy('id');
            $taxRate = null;

            $return = PurchaseReturn::create([
                'number' => DocumentNumber::generate(PurchaseReturn::class, 'RB', $date),
                'supplier_id' => $po->supplier_id,
                'purchase_order_id' => $po->id,
                'return_date' => $date,
                'status' => ReturnStatus::Posted,
                'reason' => $data['reason'],
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            foreach ($quantities as $itemId => $qty) {
                $item = $items->get($itemId);

                if (! $item) {
                    continue;
                }

                if ($qty > $item->returnableQuantity() + 0.0001) {
                    throw ValidationException::withMessages([
                        "items.$itemId" => "Jumlah retur {$item->material->name} melebihi barang diterima yang belum diretur (".qty($item->returnableQuantity()).').',
                    ]);
                }

                $billedQty = round(max(0, $qty - $item->unbilledQuantity()), 2);

                // Harga & diskon mengikuti tagihan supplier terakhir untuk bahan ini (bila sudah ditagih)
                $price = (float) $item->price;
                $factor = 1.0;
                if ($billedQty > 0 && $billLine = $this->latestBillLine($item->id)) {
                    $price = (float) $billLine->price;
                    $factor = (float) $billLine->bill->subtotal > 0 ? 1 - (float) $billLine->bill->discount / (float) $billLine->bill->subtotal : 1;
                    $taxRate ??= (float) $billLine->bill->tax_rate;
                }

                $item->returned_quantity = (float) $item->returned_quantity + $qty;
                $item->returned_billed_quantity = (float) $item->returned_billed_quantity + $billedQty;
                $item->save();

                $movement = $this->stock->move(
                    $item->material, -1 * $qty, StockMovementType::PurchaseReturn,
                    $return, $return->number, 'Retur '.$po->number.' ke '.$po->supplier->name, $date,
                );
                $unitCost = (float) $movement->unit_cost;

                $return->items()->create([
                    'purchase_order_item_id' => $item->id,
                    'material_id' => $item->material_id,
                    'quantity' => $qty,
                    'billed_quantity' => $billedQty,
                    'price' => $price,
                    'subtotal' => round($qty * $price, 2),
                    'amount' => round($billedQty * $price * $factor, 2),
                    'unit_cost' => $unitCost,
                    'cost' => round($qty * $unitCost, 2),
                    'billed_cost' => round($billedQty * $unitCost, 2),
                ]);
            }

            $this->finalizeNote($return, $taxRate ?? 0);

            $this->autoApply($return, $po->bills()->open()->orderBy('bill_date')->orderBy('id')->get(), $date);

            ActivityLog::record('created', "Mencatat retur pembelian {$return->number} dari PO {$po->number}", $return);
            ActivityLog::record('returned', "Retur barang {$return->number}", $po);

            return $return;
        });
    }

    /**
     * Pakai sisa nota untuk mengurangi invoice/tagihan terbuka lain milik pihak yang sama.
     */
    public function apply(SalesReturn|PurchaseReturn $return, int $documentId, float $amount, ?string $date = null): void
    {
        $amount = round($amount, 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Jumlah harus lebih dari 0.']);
        }

        DB::transaction(function () use ($return, $documentId, $amount, $date) {
            $return = $return->newQuery()->lockForUpdate()->findOrFail($return->id);
            [$model, $partyColumn] = $return instanceof SalesReturn ? [Invoice::class, 'customer_id'] : [Bill::class, 'supplier_id'];
            $doc = $model::whereKey($documentId)->where($partyColumn, $return->{$partyColumn})->lockForUpdate()->first();

            if (! $doc || ! $doc->isOpen()) {
                throw ValidationException::withMessages(['document_id' => 'Dokumen tidak ditemukan, sudah lunas, atau bukan milik pihak yang sama.']);
            }

            if ($amount > $return->balance() + 0.009) {
                throw ValidationException::withMessages(['amount' => 'Jumlah melebihi sisa nota ('.rupiah($return->balance()).').']);
            }

            if ($amount > $doc->outstanding() + 0.009) {
                throw ValidationException::withMessages(['amount' => "Jumlah melebihi sisa {$doc->number} (".rupiah($doc->outstanding()).').']);
            }

            $this->applyTo($return, $doc, $amount, $date ? Carbon::parse($date) : today());
        });
    }

    /**
     * Catat pengembalian sisa nota sebagai uang (ke pelanggan / dari supplier).
     *
     * @param  array{refund_date: string, refund_method: string, refund_reference?: string|null, amount: float|string}  $data
     */
    public function refund(SalesReturn|PurchaseReturn $return, array $data): void
    {
        $amount = round((float) $data['amount'], 2);

        DB::transaction(function () use ($return, $data, $amount) {
            $return = $return->newQuery()->lockForUpdate()->findOrFail($return->id);

            if ($amount <= 0 || $amount > $return->balance() + 0.009) {
                throw ValidationException::withMessages(['amount' => 'Jumlah pengembalian harus antara 1 dan sisa nota ('.rupiah($return->balance()).').']);
            }

            $return->update([
                'refunded_amount' => round((float) $return->refunded_amount + $amount, 2),
                'refund_date' => Carbon::parse($data['refund_date']),
                'refund_method' => $data['refund_method'],
                'refund_reference' => $data['refund_reference'] ?? null,
            ]);

            $verb = $return instanceof SalesReturn ? 'Mengembalikan dana ke pelanggan' : 'Menerima pengembalian dana dari supplier';
            ActivityLog::record('refunded', "{$verb} ".rupiah($amount), $return);
        });
    }

    /**
     * Batalkan retur: stok dikembalikan seperti sebelum retur dan nota yang terpakai dilepas.
     */
    public function cancel(SalesReturn|PurchaseReturn $return, string $reason): void
    {
        if (! $return->canCancel()) {
            throw ValidationException::withMessages(['status' => 'Retur yang sudah dibatalkan atau sudah ada pengembalian dana tidak dapat dibatalkan.']);
        }

        DB::transaction(function () use ($return, $reason) {
            $isSales = $return instanceof SalesReturn;
            $date = today();

            foreach ($return->items()->with($isSales ? 'salesOrderItem.variant' : 'purchaseOrderItem.material')->get() as $line) {
                $orderItem = ($isSales ? $line->salesOrderItem() : $line->purchaseOrderItem())->lockForUpdate()->first();
                $billedColumn = $isSales ? 'returned_invoiced_quantity' : 'returned_billed_quantity';
                $orderItem->returned_quantity = max(0, (float) $orderItem->returned_quantity - (float) $line->quantity);
                $orderItem->{$billedColumn} = max(0, (float) $orderItem->{$billedColumn} - (float) $line->billed_quantity);
                $orderItem->save();

                if ($isSales) {
                    $this->stock->move($orderItem->variant, -1 * (float) $line->quantity, StockMovementType::SalesReturn,
                        $return, $return->number, 'Pembatalan retur '.$return->number, $date);
                } else {
                    $this->stock->move($orderItem->material, (float) $line->quantity, StockMovementType::PurchaseReturn,
                        $return, $return->number, 'Pembatalan retur '.$return->number, $date,
                        unitCost: (float) $line->unit_cost > 0 ? (float) $line->unit_cost : null);
                }
            }

            $documents = $return->applications()->with('payable')->get()->pluck('payable');
            $return->applications()->delete();

            foreach ($documents as $doc) {
                $doc->newQuery()->lockForUpdate()->find($doc->id)?->refreshPaymentStatus();
                ActivityLog::record('credit_cancelled', "Nota retur {$return->number} dibatalkan", $doc);
            }

            $return->update(['status' => ReturnStatus::Cancelled, 'applied_amount' => 0, 'cancel_reason' => $reason]);

            ActivityLog::record('cancelled', "Membatalkan retur: {$reason}", $return);
            ActivityLog::record('return_cancelled', "Retur {$return->number} dibatalkan", $isSales ? $return->salesOrder : $return->purchaseOrder);
        });
    }

    /**
     * @return array<int, float>
     */
    private function filterQuantities(array $quantities): array
    {
        $quantities = array_filter(array_map(fn ($q) => round((float) $q, 2), $quantities), fn ($q) => $q > 0);

        if (empty($quantities)) {
            throw ValidationException::withMessages(['items' => 'Isi minimal satu jumlah barang yang diretur.']);
        }

        return $quantities;
    }

    private function finalizeNote(SalesReturn|PurchaseReturn $return, float $taxRate): void
    {
        $amount = round((float) $return->items()->sum('amount'), 2);

        if ($return->items()->count() === 0) {
            throw ValidationException::withMessages(['items' => 'Item yang dipilih tidak ditemukan pada dokumen ini.']);
        }

        $taxRate = $amount > 0 ? $taxRate : 0;
        $taxAmount = round($amount * $taxRate / 100);

        $return->update([
            'amount' => $amount,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'total' => $amount + $taxAmount,
        ]);
    }

    /**
     * @param  iterable<Invoice|Bill>  $documents  dokumen terbuka, urut dari yang terlama
     */
    private function autoApply(SalesReturn|PurchaseReturn $return, iterable $documents, Carbon $date): void
    {
        foreach ($documents as $doc) {
            $amount = min($return->balance(), $doc->outstanding());

            if ($amount <= 0.009) {
                continue;
            }

            $this->applyTo($return, $doc, $amount, $date);

            if (! $return->hasBalance()) {
                break;
            }
        }
    }

    private function applyTo(SalesReturn|PurchaseReturn $return, Model $doc, float $amount, Carbon $date): void
    {
        $return->applications()->create([
            'payable_type' => $doc->getMorphClass(),
            'payable_id' => $doc->id,
            'applied_date' => $date,
            'amount' => round($amount, 2),
            'created_by' => auth()->id(),
        ]);
        $return->refreshAppliedAmount();
        $doc->refreshPaymentStatus();

        $label = $return instanceof SalesReturn ? 'Nota kredit' : 'Nota debit';
        ActivityLog::record('credited', "{$label} retur {$return->number} sebesar ".rupiah($amount), $doc);
        ActivityLog::record('applied', "Dipakai untuk {$doc->number} sebesar ".rupiah($amount), $return);
    }

    private function latestBillLine(int $purchaseOrderItemId): ?BillItem
    {
        return BillItem::with('bill')
            ->where('purchase_order_item_id', $purchaseOrderItemId)
            ->whereHas('bill', fn ($q) => $q->where('status', '!=', InvoiceStatus::Cancelled))
            ->latest('id')
            ->first();
    }
}
