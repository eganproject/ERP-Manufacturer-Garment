<?php

namespace App\Services;

use App\Enums\ProductionCostType;
use App\Enums\ProductionOrderStatus;
use App\Enums\ProductionStage;
use App\Enums\StageStatus;
use App\Enums\StockMovementType;
use App\Enums\SubcontractStatus;
use App\Models\ActivityLog;
use App\Models\Bom;
use App\Models\ProductionCost;
use App\Models\ProductionOrder;
use App\Models\ProductionStage as Stage;
use App\Services\Accounting\AutoJournal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionService
{
    public function __construct(private StockService $stock, private AutoJournal $journal) {}

    /**
     * Simpan rincian varian + hitung ulang kebutuhan bahan dari BOM.
     *
     * @param  array<int, float>  $variantQuantities  product_variant_id => qty
     */
    public function syncItems(ProductionOrder $order, array $variantQuantities): void
    {
        $variantQuantities = array_filter(array_map('floatval', $variantQuantities), fn ($q) => $q > 0);

        if (empty($variantQuantities)) {
            throw ValidationException::withMessages(['items' => 'Isi jumlah produksi minimal untuk satu ukuran/varian.']);
        }

        $validVariantIds = $order->product->variants()->pluck('id')->all();

        $order->items()->delete();

        foreach ($variantQuantities as $variantId => $qty) {
            if (! in_array((int) $variantId, $validVariantIds, true)) {
                throw ValidationException::withMessages(['items' => 'Varian tidak sesuai dengan produk yang dipilih.']);
            }

            $order->items()->create(['product_variant_id' => $variantId, 'quantity' => $qty]);
        }

        $total = array_sum($variantQuantities);
        $order->update(['quantity' => $total]);

        $bom = Bom::with('items')->findOrFail($order->bom_id);

        $order->materials()->delete();

        foreach ($bom->requirementsFor($total) as $materialId => $required) {
            $order->materials()->create(['material_id' => $materialId, 'required_quantity' => $required]);
        }

        if ($order->stages()->doesntExist()) {
            foreach (ProductionStage::cases() as $i => $stage) {
                $order->stages()->create(['stage' => $stage, 'sequence' => $i + 1]);
            }
        }
    }

    /**
     * Mulai produksi: keluarkan bahan dari gudang sesuai kebutuhan BOM.
     * Bahan yang dialokasikan ke order maklon tidak ikut keluar; bahan itu dikirim lewat surat jalan bahan maklon.
     */
    public function start(ProductionOrder $order): void
    {
        if ($order->status !== ProductionOrderStatus::Draft) {
            throw ValidationException::withMessages(['status' => 'Hanya SPK berstatus Draft yang dapat dimulai.']);
        }

        DB::transaction(function () use ($order) {
            $reserved = SubcontractService::reservedForVendors($order);

            foreach ($order->materials()->with('material')->get() as $line) {
                $qty = round((float) $line->required_quantity - (float) $line->issued_quantity - ($reserved[$line->material_id] ?? 0), 2);

                if ($qty <= 0) {
                    continue;
                }

                $movement = $this->stock->move(
                    $line->material, -1 * $qty, StockMovementType::ProductionIssue,
                    $order, $order->number, 'Pengeluaran bahan produksi', warehouse: $order->warehouse_id,
                );
                $line->update([
                    'unit_cost' => SubcontractService::weightedCost((float) $line->issued_quantity, (float) $line->unit_cost, $qty, (float) $movement->unit_cost),
                    'issued_quantity' => (float) $line->issued_quantity + $qty,
                ]);
            }

            $this->journal->productionIssue($order);
            $this->addStandardCosts($order);

            $order->update([
                'status' => ProductionOrderStatus::InProgress,
                'materials_issued_at' => now(),
            ]);

            ActivityLog::record('started', 'Memulai produksi dan mengeluarkan bahan dari gudang', $order);
        });
    }

    public function updateStage(Stage $stage, array $data): void
    {
        $order = $stage->productionOrder;

        if ($order->status !== ProductionOrderStatus::InProgress) {
            throw ValidationException::withMessages(['status' => 'Tahapan hanya dapat diperbarui saat SPK Dalam Proses.']);
        }

        if ($stage->isSubcontracted() && $stage->subcontractOrder->isOpen()) {
            throw ValidationException::withMessages(['status' => "Tahap {$stage->stage->label()} dikerjakan maklon ({$stage->subcontractOrder->number}); progres diperbarui lewat penerimaan hasil."]);
        }

        $previous = $order->stages()->where('sequence', '<', $stage->sequence)->where('status', '!=', StageStatus::Done->value)->exists();
        $status = StageStatus::from($data['status']);

        if ($previous && $status !== StageStatus::Pending) {
            throw ValidationException::withMessages(['status' => 'Selesaikan tahapan sebelumnya terlebih dahulu.']);
        }

        $stage->fill([
            'status' => $status,
            'output_quantity' => $data['output_quantity'] ?? 0,
            'reject_quantity' => $data['reject_quantity'] ?? 0,
            'pic' => $data['pic'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);

        if ($status !== StageStatus::Pending && ! $stage->started_at) {
            $stage->started_at = now();
        }

        $stage->finished_at = $status === StageStatus::Done ? ($stage->finished_at ?? now()) : null;
        $stage->save();

        ActivityLog::record('stage', "Tahap {$stage->stage->label()}: {$status->label()}", $order);
    }

    /**
     * Selesaikan produksi: barang jadi masuk ke stok per varian.
     *
     * @param  array<int, float>  $finished  production_order_item_id => qty jadi
     */
    public function complete(ProductionOrder $order, array $finished, float $reject = 0): void
    {
        if ($order->status !== ProductionOrderStatus::InProgress) {
            throw ValidationException::withMessages(['status' => 'Hanya SPK Dalam Proses yang dapat diselesaikan.']);
        }

        if ($order->stages()->where('status', '!=', StageStatus::Done->value)->exists()) {
            throw ValidationException::withMessages(['status' => 'Semua tahapan produksi harus selesai terlebih dahulu.']);
        }

        if ($open = $order->subcontractOrders()->where('status', SubcontractStatus::Open)->first()) {
            throw ValidationException::withMessages(['status' => "Tutup order maklon {$open->number} terlebih dahulu (retur sisa bahan & rekonsiliasi)."]);
        }

        DB::transaction(function () use ($order, $finished, $reject) {
            $total = 0;

            foreach ($order->items()->with('variant.product')->get() as $item) {
                $qty = max(0, (float) ($finished[$item->id] ?? 0));

                if ($qty > (float) $item->quantity) {
                    throw ValidationException::withMessages([
                        "finished.{$item->id}" => "Jumlah jadi {$item->variant->display_name} melebihi jumlah rencana.",
                    ]);
                }

                $item->update(['finished_quantity' => $qty]);
                $total += $qty;
            }

            if ($total <= 0) {
                throw ValidationException::withMessages(['finished' => 'Isi jumlah barang jadi minimal untuk satu ukuran.']);
            }

            // Barang jadi tidak boleh melebihi output tahap terakhir (packing)
            $lastStage = $order->stages()->reorder('sequence', 'desc')->first();

            if ($lastStage && (float) $lastStage->output_quantity > 0 && $total > (float) $lastStage->output_quantity + 0.0001) {
                throw ValidationException::withMessages([
                    'finished' => 'Total barang jadi ('.qty($total).' pcs) melebihi output tahap '.$lastStage->stage->label().' ('.qty($lastStage->output_quantity).' pcs). Sesuaikan jumlah jadi per ukuran.',
                ]);
            }

            // HPP aktual: bahan yang dikeluarkan + biaya konversi, dibebankan ke barang jadi (bukan reject)
            $materialCost = round($order->materials()->get()->sum(fn ($line) => (float) $line->issued_quantity * (float) $line->unit_cost), 2);
            $conversionCost = round((float) $order->costs()->sum('amount'), 2);
            $totalCost = $materialCost + $conversionCost;
            $unitCost = round($totalCost / $total, 2);

            $order->update([
                'status' => ProductionOrderStatus::Completed,
                'finished_quantity' => $total,
                'reject_quantity' => $reject,
                'material_cost' => $materialCost,
                'conversion_cost' => $conversionCost,
                'total_cost' => $totalCost,
                'unit_cost' => $unitCost,
                'completed_at' => now(),
            ]);

            foreach ($order->items()->with('variant.product')->get() as $item) {
                if ((float) $item->finished_quantity > 0) {
                    $this->stock->move(
                        $item->variant, (float) $item->finished_quantity, StockMovementType::ProductionOutput,
                        $order, $order->number, 'Hasil produksi', unitCost: $unitCost, warehouse: $order->warehouse_id,
                    );
                }
            }

            $this->journal->productionComplete($order);

            ActivityLog::record('completed', 'Menyelesaikan produksi, hasil '.qty($total).' pcs masuk stok, HPP '.rupiah($unitCost).'/pcs', $order);
        });
    }

    /**
     * Catat biaya konversi (upah, CMT, overhead) pada SPK.
     *
     * @param  array{type: string, description: string, amount: float|string, date: string}  $data
     */
    public function addCost(ProductionOrder $order, array $data): ProductionCost
    {
        if (! $order->canRecordCosts()) {
            throw ValidationException::withMessages(['status' => 'Biaya hanya dapat dicatat sebelum SPK selesai.']);
        }

        return DB::transaction(function () use ($order, $data) {
            $cost = $order->costs()->create([
                'type' => ProductionCostType::from($data['type']),
                'description' => $data['description'],
                'amount' => $data['amount'],
                'date' => $data['date'],
                'created_by' => auth()->id(),
            ]);

            $this->journal->productionCost($cost);

            ActivityLog::record('cost', "Mencatat biaya {$cost->type->label()}: {$cost->description} ".rupiah($cost->amount), $order);

            return $cost;
        });
    }

    public function removeCost(ProductionCost $cost): void
    {
        $order = $cost->productionOrder;

        if (! $order->canRecordCosts()) {
            throw ValidationException::withMessages(['status' => 'Biaya SPK yang sudah selesai tidak dapat dihapus.']);
        }

        if ($cost->piecework_sheet_id) {
            throw ValidationException::withMessages(['status' => 'Upah borongan tercatat otomatis dari hasil kerja harian; hapus lembar hasil kerjanya bila perlu koreksi.']);
        }

        if ($cost->subcontract_receipt_id) {
            throw ValidationException::withMessages(['status' => 'Biaya jasa maklon tercatat otomatis dari penerimaan hasil dan tidak dapat dihapus manual.']);
        }

        DB::transaction(function () use ($cost, $order) {
            $this->journal->reverse($order, 'biaya dihapus', AutoJournal::costEvent($cost));
            $cost->delete();
            ActivityLog::record('cost', "Menghapus biaya {$cost->type->label()}: {$cost->description} ".rupiah($cost->amount), $order);
        });
    }

    /**
     * Saat produksi dimulai, bebankan upah & overhead standar BOM (dapat dikoreksi sebelum SPK ditutup).
     */
    private function addStandardCosts(ProductionOrder $order): void
    {
        $bom = $order->bom;
        $qty = (float) $order->quantity;

        // Bila jahit dimaklonkan, upah jahit diganti ongkos jasa vendor (dicatat saat hasil diterima)
        $sewingSubcontracted = $order->stages()->where('stage', ProductionStage::Sewing->value)->whereNotNull('subcontract_order_id')->exists();

        // Artikel dengan tarif borongan: upah aktual masuk dari hasil kerja harian karyawan
        $piecework = $order->product->usesPiecework();

        $standards = [
            [ProductionCostType::Labor, 'Upah jahit standar BOM', $sewingSubcontracted || $piecework ? 0.0 : (float) $bom->labor_cost],
            [ProductionCostType::Overhead, 'Overhead standar BOM', (float) $bom->overhead_cost],
        ];

        foreach ($standards as [$type, $label, $rate]) {
            if ($rate > 0) {
                $cost = $order->costs()->create([
                    'type' => $type,
                    'description' => $label.' ('.qty($qty).' pcs × '.rupiah($rate).')',
                    'amount' => round($rate * $qty, 2),
                    'date' => now()->toDateString(),
                    'created_by' => auth()->id(),
                ]);
                $this->journal->productionCost($cost);
            }
        }
    }
}
