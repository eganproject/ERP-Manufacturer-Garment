<?php

namespace App\Services;

use App\Enums\ProductionCostType;
use App\Enums\ProductionOrderStatus;
use App\Enums\ProductionStage as Stage;
use App\Enums\StageStatus;
use App\Enums\StockMovementType;
use App\Enums\SubcontractStatus;
use App\Enums\SubcontractTransferType;
use App\Models\ActivityLog;
use App\Models\ProductionMaterial;
use App\Models\ProductionOrder;
use App\Models\SubcontractOrder;
use App\Models\SubcontractOrderMaterial;
use App\Models\SubcontractReceipt;
use App\Models\SubcontractTransfer;
use App\Models\Supplier;
use App\Services\Accounting\AutoJournal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Produksi maklon: tahap SPK dikerjakan vendor luar, bahan dikirim dari gudang perusahaan.
 *
 * Alur: order maklon → kirim bahan (stok gudang keluar, saldo titipan di vendor) → terima hasil
 * (bagus/reject, ongkos jasa masuk HPP SPK) → retur sisa bahan → tutup order (selisih = susut) → tagihan vendor.
 *
 * Bahan yang dikirim ke vendor langsung dibebankan ke SPK (production_materials.issued_quantity);
 * retur sisa bahan mengurangi beban tersebut dan mengembalikan stok gudang.
 */
class SubcontractService
{
    public function __construct(private StockService $stock, private AutoJournal $journal) {}

    /**
     * @param  array{supplier_id: int, order_date: string, due_date?: string|null, stages: list<string>, quantity: float|string, rate: float|string, notes?: string|null}  $data
     * @param  array<int, float|string|null>  $materials  material_id => rencana kirim
     */
    public function create(ProductionOrder $spk, array $data, array $materials): SubcontractOrder
    {
        if (! in_array($spk->status, [ProductionOrderStatus::Draft, ProductionOrderStatus::InProgress], true)) {
            throw ValidationException::withMessages(['status' => 'Order maklon hanya dapat dibuat untuk SPK Draft atau Dalam Proses.']);
        }

        $supplier = Supplier::findOrFail($data['supplier_id']);

        if (! $supplier->is_subcontractor) {
            throw ValidationException::withMessages(['supplier_id' => "{$supplier->name} belum ditandai sebagai vendor maklon."]);
        }

        $stages = collect($data['stages'])->map(fn ($s) => Stage::from($s));

        if ($stages->isEmpty()) {
            throw ValidationException::withMessages(['stages' => 'Pilih minimal satu tahap yang dikerjakan vendor.']);
        }

        $quantity = (float) $data['quantity'];

        if ($quantity <= 0 || $quantity > (float) $spk->quantity) {
            throw ValidationException::withMessages(['quantity' => 'Jumlah maklon harus antara 1 dan jumlah SPK ('.qty($spk->quantity).' pcs).']);
        }

        return DB::transaction(function () use ($spk, $supplier, $data, $stages, $quantity, $materials) {
            $stageRows = $spk->stages()->lockForUpdate()->get();
            $selected = $stageRows->filter(fn ($row) => $stages->contains($row->stage));

            if ($taken = $selected->first(fn ($row) => $row->subcontract_order_id !== null || $row->status === StageStatus::Done)) {
                throw ValidationException::withMessages(['stages' => "Tahap {$taken->stage->label()} sudah selesai atau sudah dimaklonkan."]);
            }

            $date = Carbon::parse($data['order_date']);

            $order = SubcontractOrder::create([
                'number' => DocumentNumber::generate(SubcontractOrder::class, 'MKL', $date),
                'production_order_id' => $spk->id,
                'supplier_id' => $supplier->id,
                'order_date' => $date,
                'due_date' => $data['due_date'] ?? null,
                'stages' => $stages->map->value->values()->all(),
                'status' => SubcontractStatus::Open,
                'quantity' => $quantity,
                'rate' => (float) $data['rate'],
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            foreach ($spk->materials as $line) {
                $order->materials()->create([
                    'material_id' => $line->material_id,
                    'planned_quantity' => max(0, (float) ($materials[$line->material_id] ?? 0)),
                ]);
            }

            $spk->stages()->whereIn('id', $selected->pluck('id'))->update(['subcontract_order_id' => $order->id]);

            ActivityLog::record('created', "Membuat order maklon {$order->number} ke {$supplier->name} untuk SPK {$spk->number} ({$order->stagesLabel()})", $order);
            ActivityLog::record('subcontract', "Tahap {$order->stagesLabel()} dimaklonkan ke {$supplier->name} ({$order->number})", $spk);

            return $order;
        });
    }

    /**
     * Rencana kirim default per bahan: kebutuhan SPK yang belum dikeluarkan / dialokasikan ke vendor lain.
     *
     * @return array<int, float> material_id => qty
     */
    public static function suggestedMaterials(ProductionOrder $spk, float $quantity): array
    {
        $reserved = self::reservedForVendors($spk);
        $ratio = (float) $spk->quantity > 0 ? $quantity / (float) $spk->quantity : 0;

        return $spk->materials->mapWithKeys(fn (ProductionMaterial $line) => [
            $line->material_id => round(max(0, min(
                (float) $line->required_quantity * $ratio,
                (float) $line->required_quantity - (float) $line->issued_quantity - ($reserved[$line->material_id] ?? 0),
            )), 2),
        ])->all();
    }

    /**
     * Bahan yang direncanakan dikirim ke vendor tapi belum dikirim (tidak ikut dikeluarkan saat SPK mulai).
     *
     * @return array<int, float> material_id => qty
     */
    public static function reservedForVendors(ProductionOrder $spk): array
    {
        return SubcontractOrderMaterial::query()
            ->whereHas('subcontractOrder', fn ($q) => $q->where('production_order_id', $spk->id)->where('status', SubcontractStatus::Open))
            ->get()
            ->groupBy('material_id')
            ->map(fn ($lines) => round($lines->sum(fn (SubcontractOrderMaterial $m) => $m->unsentQuantity()), 2))
            ->all();
    }

    /**
     * Kirim bahan dari gudang ke vendor (surat jalan bahan maklon).
     *
     * @param  array<int, float|string|null>  $lines  subcontract_order_material_id => qty
     * @param  array{date: string, vehicle_number?: string|null, driver_name?: string|null, notes?: string|null}  $data
     */
    public function dispatch(SubcontractOrder $order, array $lines, array $data): SubcontractTransfer
    {
        $this->ensureOpen($order);

        if ($order->productionOrder->status !== ProductionOrderStatus::InProgress) {
            throw ValidationException::withMessages(['status' => 'Mulai produksi SPK terlebih dahulu sebelum mengirim bahan ke vendor.']);
        }

        return $this->transfer($order, SubcontractTransferType::Dispatch, $lines, $data);
    }

    /**
     * Retur sisa bahan dari vendor ke gudang.
     *
     * @param  array<int, float|string|null>  $lines  subcontract_order_material_id => qty
     */
    public function returnMaterials(SubcontractOrder $order, array $lines, array $data): SubcontractTransfer
    {
        $this->ensureOpen($order);

        return $this->transfer($order, SubcontractTransferType::Return, $lines, $data);
    }

    private function transfer(SubcontractOrder $order, SubcontractTransferType $type, array $lines, array $data): SubcontractTransfer
    {
        $lines = array_filter(array_map('floatval', $lines), fn ($q) => $q > 0);

        if (empty($lines)) {
            throw ValidationException::withMessages(['items' => 'Isi jumlah minimal untuk satu bahan.']);
        }

        return DB::transaction(function () use ($order, $type, $lines, $data) {
            $date = Carbon::parse($data['date']);
            $spk = $order->productionOrder;
            $materials = $order->materials()->with('material')->lockForUpdate()->get()->keyBy('id');

            $transfer = $order->transfers()->create([
                'number' => DocumentNumber::generate(SubcontractTransfer::class, $type->prefix(), $date),
                'type' => $type,
                'date' => $date,
                'vehicle_number' => $data['vehicle_number'] ?? null,
                'driver_name' => $data['driver_name'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            foreach ($lines as $lineId => $qty) {
                $line = $materials->get($lineId) ?? throw ValidationException::withMessages(['items' => 'Bahan tidak terdaftar pada order maklon ini.']);
                $spkLine = $spk->materials()->where('material_id', $line->material_id)->lockForUpdate()->firstOrFail();

                if ($type === SubcontractTransferType::Dispatch) {
                    $movement = $this->stock->move(
                        $line->material, -1 * $qty, StockMovementType::SubcontractIssue, $transfer, $transfer->number,
                        "Kirim ke {$order->supplier->name} ({$order->number})", $date,
                    );
                    $unitCost = (float) $movement->unit_cost;

                    $line->unit_cost = self::weightedCost((float) $line->sent_quantity, (float) $line->unit_cost, $qty, $unitCost);
                    $line->sent_quantity = (float) $line->sent_quantity + $qty;

                    $spkLine->unit_cost = self::weightedCost((float) $spkLine->issued_quantity, (float) $spkLine->unit_cost, $qty, $unitCost);
                    $spkLine->issued_quantity = (float) $spkLine->issued_quantity + $qty;
                } else {
                    if ($qty > $line->balance() + 0.0001) {
                        throw ValidationException::withMessages([
                            "items.{$lineId}" => "Retur {$line->material->name} melebihi saldo di vendor (".qty($line->balance()).').',
                        ]);
                    }

                    $unitCost = (float) $line->unit_cost;
                    $this->stock->move(
                        $line->material, $qty, StockMovementType::SubcontractReturn, $transfer, $transfer->number,
                        "Retur sisa bahan dari {$order->supplier->name} ({$order->number})", $date, unitCost: $unitCost,
                    );

                    $line->returned_quantity = (float) $line->returned_quantity + $qty;
                    $spkLine->issued_quantity = max(0, (float) $spkLine->issued_quantity - $qty);
                }

                $line->save();
                $spkLine->save();

                $transfer->items()->create([
                    'subcontract_order_material_id' => $line->id,
                    'material_id' => $line->material_id,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                ]);
            }

            $this->journal->subcontractTransfer($transfer);

            if ($type === SubcontractTransferType::Dispatch && ! $spk->materials_issued_at) {
                $spk->update(['materials_issued_at' => now()]);
            }

            $verb = $type === SubcontractTransferType::Dispatch ? "Mengirim bahan ke {$order->supplier->name}" : "Menerima retur sisa bahan dari {$order->supplier->name}";
            ActivityLog::record($type->value, "{$verb} ({$transfer->number})", $order);
            ActivityLog::record('subcontract', "{$verb} ({$transfer->number}, {$order->number})", $spk);

            return $transfer;
        });
    }

    /**
     * Terima hasil dari vendor. Ongkos jasa (bagus × tarif) dibebankan ke SPK sebagai biaya CMT/maklon.
     *
     * @param  array{date: string, good_quantity: float|string, reject_quantity?: float|string|null, vendor_reference?: string|null, notes?: string|null}  $data
     */
    public function receive(SubcontractOrder $order, array $data): SubcontractReceipt
    {
        $this->ensureOpen($order);
        $spk = $order->productionOrder;

        if ($spk->status !== ProductionOrderStatus::InProgress) {
            throw ValidationException::withMessages(['status' => 'Hasil maklon hanya dapat diterima saat SPK Dalam Proses.']);
        }

        $good = max(0, (float) $data['good_quantity']);
        $reject = max(0, (float) ($data['reject_quantity'] ?? 0));

        if ($good + $reject <= 0) {
            throw ValidationException::withMessages(['good_quantity' => 'Isi jumlah hasil bagus atau reject.']);
        }

        if ($good + $reject > $order->outstandingQuantity() + 0.0001) {
            throw ValidationException::withMessages(['good_quantity' => 'Jumlah diterima melebihi sisa order maklon ('.qty($order->outstandingQuantity()).' pcs).']);
        }

        $stages = $order->productionStages()->get();
        $firstSequence = $stages->min('sequence');

        if ($spk->stages()->where('sequence', '<', $firstSequence)->where('status', '!=', StageStatus::Done->value)->exists()) {
            throw ValidationException::withMessages(['status' => 'Selesaikan tahap sebelum tahap maklon terlebih dahulu.']);
        }

        return DB::transaction(function () use ($order, $spk, $data, $good, $reject, $stages) {
            $date = Carbon::parse($data['date']);
            $amount = round($good * (float) $order->rate, 2);

            $receipt = $order->receipts()->create([
                'number' => DocumentNumber::generate(SubcontractReceipt::class, 'TMK', $date),
                'date' => $date,
                'vendor_reference' => $data['vendor_reference'] ?? null,
                'good_quantity' => $good,
                'reject_quantity' => $reject,
                'rate' => $order->rate,
                'amount' => $amount,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            if ($amount > 0) {
                $cost = $spk->costs()->create([
                    'subcontract_receipt_id' => $receipt->id,
                    'type' => ProductionCostType::Cmt,
                    'description' => "Jasa maklon {$order->supplier->name} {$receipt->number} (".qty($good).' pcs × '.rupiah($order->rate).')',
                    'amount' => $amount,
                    'date' => $date,
                    'created_by' => auth()->id(),
                ]);
                $this->journal->productionCost($cost);
            }

            $order->update([
                'received_quantity' => (float) $order->received_quantity + $good,
                'reject_quantity' => (float) $order->reject_quantity + $reject,
                'service_cost' => (float) $order->service_cost + $amount,
            ]);

            $this->syncStages($order, $stages, $order->outstandingQuantity() <= 0);

            ActivityLog::record('received', "Menerima hasil {$receipt->number}: ".qty($good).' bagus, '.qty($reject).' reject, jasa '.rupiah($amount), $order);
            ActivityLog::record('subcontract', "Hasil maklon {$receipt->number} dari {$order->supplier->name}: ".qty($good).' bagus, '.qty($reject).' reject', $spk);

            return $receipt;
        });
    }

    /**
     * Tutup order: sisa bahan yang tidak diretur dianggap terpakai/susut di vendor, tahap maklon selesai.
     */
    public function close(SubcontractOrder $order, ?string $notes = null): void
    {
        $this->ensureOpen($order);

        if ($order->receipts()->doesntExist()) {
            throw ValidationException::withMessages(['status' => 'Belum ada hasil yang diterima. Batalkan order bila tidak jadi dimaklonkan.']);
        }

        DB::transaction(function () use ($order, $notes) {
            $shrinkage = [];

            foreach ($order->materials()->with('material.unit')->lockForUpdate()->get() as $line) {
                $used = round((float) $line->sent_quantity - (float) $line->returned_quantity, 2);
                $line->update(['used_quantity' => max(0, $used)]);

                $variance = round($used - $line->standardUsage(), 2);
                if ($used > 0 && abs($variance) >= 0.01) {
                    $shrinkage[] = "{$line->material->name} ".($variance > 0 ? '+' : '').qty($variance).' '.$line->material->unit?->code;
                }
            }

            $order->update([
                'status' => SubcontractStatus::Closed,
                'closed_at' => now(),
                'notes' => trim(($order->notes ? $order->notes."\n" : '').($notes ?? '')) ?: null,
            ]);

            $this->syncStages($order, $order->productionStages()->get(), true);
            $this->journal->subcontractClose($order);

            $summary = $shrinkage ? ' Selisih pemakaian vs standar: '.implode(', ', $shrinkage).'.' : '';
            ActivityLog::record('closed', 'Menutup order maklon, saldo bahan di vendor direkonsiliasi.'.$summary, $order);
            ActivityLog::record('subcontract', "Order maklon {$order->number} ditutup.".$summary, $order->productionOrder);
        });
    }

    public function cancel(SubcontractOrder $order, ?string $reason = null): void
    {
        if (! $order->canCancel()) {
            throw ValidationException::withMessages(['status' => 'Order maklon yang sudah ada pengiriman bahan atau penerimaan hasil tidak dapat dibatalkan.']);
        }

        DB::transaction(function () use ($order, $reason) {
            $order->productionStages()->update(['subcontract_order_id' => null]);
            $order->update(['status' => SubcontractStatus::Cancelled]);

            ActivityLog::record('cancelled', 'Membatalkan order maklon'.($reason ? ": {$reason}" : ''), $order);
            ActivityLog::record('subcontract', "Order maklon {$order->number} dibatalkan, tahap kembali dikerjakan internal", $order->productionOrder);
        });
    }

    /** Perbarui output/reject tahap SPK yang dikerjakan vendor dari total penerimaan. */
    private function syncStages(SubcontractOrder $order, $stages, bool $done): void
    {
        $last = $stages->last();

        foreach ($stages as $stage) {
            $stage->fill([
                'status' => $done ? StageStatus::Done : StageStatus::InProgress,
                'output_quantity' => $order->received_quantity,
                'reject_quantity' => $stage->is($last) ? $order->reject_quantity : 0,
                'pic' => 'Maklon: '.$order->supplier->name,
            ]);
            $stage->started_at ??= now();
            $stage->finished_at = $done ? ($stage->finished_at ?? now()) : null;
            $stage->save();
        }
    }

    private function ensureOpen(SubcontractOrder $order): void
    {
        if (! $order->isOpen()) {
            throw ValidationException::withMessages(['status' => "Order maklon {$order->number} sudah {$order->status->label()}."]);
        }
    }

    public static function weightedCost(float $qty, float $cost, float $addQty, float $addCost): float
    {
        $total = $qty + $addQty;

        return $total > 0 ? round(($qty * $cost + $addQty * $addCost) / $total, 4) : $addCost;
    }
}
