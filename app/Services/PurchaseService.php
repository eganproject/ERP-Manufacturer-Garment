<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Enums\StockMovementType;
use App\Models\ActivityLog;
use App\Models\GoodsReceipt;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AutoJournal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseService
{
    public function __construct(private StockService $stock, private AutoJournal $journal) {}

    /**
     * Kunci PO draft. PO di bawah batas nilai langsung berstatus Dipesan; di atas batas
     * menunggu persetujuan (Pengaturan → Batas persetujuan PO).
     */
    public function submit(PurchaseOrder $po): PurchaseOrder
    {
        if (! $po->isEditable()) {
            throw ValidationException::withMessages(['status' => 'Hanya PO draft yang dapat diajukan / dipesan.']);
        }

        return DB::transaction(function () use ($po) {
            $po->submitted_at = now();
            $po->approved_by = null;
            $po->approved_at = null;
            $po->approval_notes = null;

            if ($po->requiresApproval()) {
                $po->status = PurchaseOrderStatus::PendingApproval;
                $po->save();
                ActivityLog::record('submitted', 'Mengajukan persetujuan PO senilai '.rupiah($po->total), $po);
            } else {
                $po->status = PurchaseOrderStatus::Ordered;
                $po->save();
                ActivityLog::record('ordered', 'Menandai PO sudah dipesan ke supplier', $po);
            }

            return $po;
        });
    }

    public function approve(PurchaseOrder $po, User $approver, ?string $notes = null): PurchaseOrder
    {
        $this->ensureCanDecide($po, $approver);

        return DB::transaction(function () use ($po, $approver, $notes) {
            $po->update([
                'status' => PurchaseOrderStatus::Ordered,
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'approval_notes' => $notes ?: null,
            ]);
            ActivityLog::record('approved', 'Menyetujui PO'.($notes ? ": {$notes}" : '').' — PO dapat dipesan ke supplier', $po);

            return $po;
        });
    }

    /** Tolak PO: kembali ke draft agar bisa direvisi lalu diajukan ulang. */
    public function reject(PurchaseOrder $po, User $approver, string $reason): PurchaseOrder
    {
        $this->ensureCanDecide($po, $approver);

        return DB::transaction(function () use ($po, $reason) {
            $po->update([
                'status' => PurchaseOrderStatus::Draft,
                'submitted_at' => null,
                'approved_by' => null,
                'approved_at' => null,
                'approval_notes' => $reason,
            ]);
            ActivityLog::record('rejected', "Menolak PO: {$reason}", $po);

            return $po;
        });
    }

    private function ensureCanDecide(PurchaseOrder $po, User $approver): void
    {
        if (! $po->isPendingApproval()) {
            throw ValidationException::withMessages(['status' => 'PO ini tidak sedang menunggu persetujuan.']);
        }

        // Pemisahan tugas: pembuat PO tidak menyetujui PO-nya sendiri (kecuali administrator)
        if ($po->created_by === $approver->id && ! $approver->isAdmin()) {
            throw ValidationException::withMessages(['status' => 'PO tidak dapat disetujui / ditolak oleh pembuatnya sendiri.']);
        }
    }

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
                'warehouse_id' => Warehouse::resolveId($data['warehouse_id'] ?? null),
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
                    unitCost: (float) $item->price, warehouse: $receipt->warehouse_id,
                );
            }

            $po->refreshReceiptStatus();
            $this->journal->goodsReceipt($receipt);

            ActivityLog::record('received', "Menerima barang {$receipt->number}", $po);

            return $receipt;
        });
    }
}
