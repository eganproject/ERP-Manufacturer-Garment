<?php

namespace App\Services;

use App\Enums\ProductionCostType;
use App\Enums\ProductionMaterialTransferType;
use App\Enums\ProductionOrderStatus;
use App\Enums\ProductionStage;
use App\Enums\StageStatus;
use App\Enums\StockLocation;
use App\Enums\StockMovementType;
use App\Enums\SubcontractStatus;
use App\Models\ActivityLog;
use App\Models\Bom;
use App\Models\Material;
use App\Models\ProductionCost;
use App\Models\ProductionMaterial;
use App\Models\ProductionMaterialTransfer;
use App\Models\ProductionOrder;
use App\Models\ProductionReceipt;
use App\Models\ProductionStage as Stage;
use App\Models\SubcontractOrderMaterial;
use App\Models\Warehouse;
use App\Services\Accounting\AutoJournal;
use Illuminate\Support\Carbon;
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

        foreach ($bom->requirementsForVariants($variantQuantities) as $materialId => $required) {
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
     * Bahan tambahan dari gudang ke SPK yang sedang berjalan (mis. kain kurang, ganti bahan rusak).
     * Bahan di luar BOM boleh ditambahkan; baris kebutuhannya dibuat dengan kebutuhan 0.
     *
     * @param  array<int|string, float|string|null>  $lines  material_id => qty
     * @param  array{date: string, notes?: string|null, warehouse_id?: int|null}  $data
     */
    public function issueMaterials(ProductionOrder $order, array $lines, array $data): ProductionMaterialTransfer
    {
        return $this->transferMaterials($order, ProductionMaterialTransferType::Issue, $lines, $data);
    }

    /**
     * Retur sisa bahan SPK ke gudang. Maksimal = bahan yang dikeluarkan untuk produksi internal
     * (bahan yang masih/pernah dipegang vendor maklon diretur lewat order maklon).
     *
     * @param  array<int|string, float|string|null>  $lines  material_id => qty
     * @param  array{date: string, notes?: string|null, warehouse_id?: int|null}  $data
     */
    public function returnMaterials(ProductionOrder $order, array $lines, array $data): ProductionMaterialTransfer
    {
        return $this->transferMaterials($order, ProductionMaterialTransferType::Return, $lines, $data);
    }

    /**
     * Bahan yang dipegang bagian produksi internal per bahan = dikeluarkan − dikirim ke vendor maklon (bersih retur).
     *
     * @return array<int, float> material_id => qty
     */
    public static function internalIssued(ProductionOrder $order): array
    {
        $atVendors = SubcontractOrderMaterial::query()
            ->whereHas('subcontractOrder', fn ($q) => $q->where('production_order_id', $order->id)->where('status', '!=', SubcontractStatus::Cancelled))
            ->get()
            ->groupBy('material_id')
            ->map(fn ($rows) => (float) $rows->sum('sent_quantity') - (float) $rows->sum('returned_quantity'));

        return $order->materials()->get()->mapWithKeys(fn (ProductionMaterial $line) => [
            $line->material_id => max(0, round((float) $line->issued_quantity - (float) ($atVendors[$line->material_id] ?? 0), 2)),
        ])->all();
    }

    private function transferMaterials(ProductionOrder $order, ProductionMaterialTransferType $type, array $lines, array $data): ProductionMaterialTransfer
    {
        if ($order->status !== ProductionOrderStatus::InProgress) {
            throw ValidationException::withMessages(['status' => "{$type->label()} hanya dapat dicatat saat SPK Dalam Proses."]);
        }

        $lines = array_filter(array_map(fn ($q) => round((float) $q, 2), $lines), fn ($q) => $q > 0);

        if (empty($lines)) {
            throw ValidationException::withMessages(['items' => 'Isi jumlah minimal untuk satu bahan.']);
        }

        return DB::transaction(function () use ($order, $type, $lines, $data) {
            $date = Carbon::parse($data['date']);
            $internal = $type === ProductionMaterialTransferType::Return ? self::internalIssued($order) : [];

            $transfer = $order->materialTransfers()->create([
                'number' => DocumentNumber::generate(ProductionMaterialTransfer::class, $type->prefix(), $date),
                'type' => $type,
                'date' => $date,
                'warehouse_id' => Warehouse::resolveId($data['warehouse_id'] ?? $order->warehouse_id),
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            foreach ($lines as $materialId => $qty) {
                $material = Material::find($materialId) ?? throw ValidationException::withMessages(['items' => 'Bahan tidak ditemukan.']);
                $line = $order->materials()->where('material_id', $material->id)->lockForUpdate()->first();

                if ($type === ProductionMaterialTransferType::Issue) {
                    $line ??= $order->materials()->create(['material_id' => $material->id, 'required_quantity' => 0]);
                    $movement = $this->stock->move(
                        $material, -1 * $qty, StockMovementType::ProductionIssue, $transfer, $transfer->number,
                        "Bahan tambahan {$order->number}", $date, warehouse: $transfer->warehouse_id,
                    );
                    $unitCost = (float) $movement->unit_cost;
                    $line->unit_cost = SubcontractService::weightedCost((float) $line->issued_quantity, (float) $line->unit_cost, $qty, $unitCost);
                    $line->issued_quantity = (float) $line->issued_quantity + $qty;
                } else {
                    $available = (float) ($internal[$material->id] ?? 0);

                    if (! $line || $qty > $available + 0.0001) {
                        throw ValidationException::withMessages([
                            "items.{$material->id}" => "Retur {$material->name} melebihi bahan yang dikeluarkan untuk produksi internal (".qty($available).').',
                        ]);
                    }

                    $unitCost = (float) $line->unit_cost;
                    $this->stock->move(
                        $material, $qty, StockMovementType::ProductionReturn, $transfer, $transfer->number,
                        "Retur sisa bahan {$order->number}", $date, unitCost: $unitCost, warehouse: $transfer->warehouse_id,
                    );
                    $line->issued_quantity = max(0, (float) $line->issued_quantity - $qty);
                    $line->returned_quantity = (float) $line->returned_quantity + $qty;
                    $internal[$material->id] = $available - $qty;
                }

                $line->save();

                $transfer->items()->create([
                    'material_id' => $material->id,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                ]);
            }

            $this->journal->productionMaterialTransfer($transfer);

            ActivityLog::record($type === ProductionMaterialTransferType::Issue ? 'material_issue' : 'material_return', "{$type->label()} {$transfer->number} senilai ".rupiah($transfer->load('items')->totalValue()), $order);

            return $transfer;
        });
    }

    /**
     * Perkiraan HPP per pcs untuk setoran hasil bertahap: perkiraan biaya penuh SPK ÷ jumlah rencana.
     * Bahan = yang sudah dikeluarkan (minimal kebutuhan BOM yang belum diretur); upah borongan & jasa maklon
     * memakai nilai terbesar antara yang sudah tercatat dan tarif × qty; biaya lain sesuai yang tercatat.
     * Selisih terhadap HPP aktual diserap setoran penutup saat SPK diselesaikan.
     */
    public function estimatedUnitCost(ProductionOrder $order): float
    {
        $order->loadMissing(['materials.material', 'costs', 'product.operations', 'subcontractOrders']);
        $planned = (float) $order->quantity;

        if ($planned <= 0) {
            return 0.0;
        }

        $material = $order->materials->sum(function (ProductionMaterial $line) {
            $cost = (float) $line->unit_cost > 0 ? (float) $line->unit_cost : $line->material->costPrice();
            $qty = max((float) $line->issued_quantity, (float) $line->required_quantity - (float) $line->returned_quantity);

            return $qty * $cost;
        });

        $piecework = (float) $order->costs->whereNotNull('piecework_sheet_id')->sum('amount');
        $subcontract = (float) $order->costs->whereNotNull('subcontract_receipt_id')->sum('amount');
        $other = (float) $order->costs->sum('amount') - $piecework - $subcontract;

        $progress = app(PieceworkService::class)->progress($order);
        $operations = $progress->except(PieceworkService::FULL);
        $expectedPiecework = $operations->isNotEmpty()
            ? $operations->sum(fn ($row) => $row['rate'] * $row['capacity'])
            : $progress[PieceworkService::FULL]['rate'] * $progress[PieceworkService::FULL]['capacity'];
        $expectedSubcontract = $order->subcontractOrders
            ->where('status', '!=', SubcontractStatus::Cancelled)
            ->sum(fn ($sub) => (float) $sub->quantity * (float) $sub->rate);

        $total = $material + $other + max($piecework, $expectedPiecework) + max($subcontract, $expectedSubcontract);

        return round($total / $planned, 2);
    }

    /**
     * Setor hasil produksi bertahap selama SPK berjalan (mis. PO marketplace dikirim per batch).
     *
     * @param  array<int|string, array{good?: float|string|null, defect?: float|string|null}>  $items  production_order_item_id => qty
     * @param  array{date: string, notes?: string|null, warehouse_id?: int|null}  $data
     */
    public function receive(ProductionOrder $order, array $items, array $data): ProductionReceipt
    {
        if ($order->status !== ProductionOrderStatus::InProgress) {
            throw ValidationException::withMessages(['status' => 'Setor hasil hanya dapat dicatat saat SPK Dalam Proses.']);
        }

        return DB::transaction(function () use ($order, $items, $data) {
            $receipt = $this->createReceipt($order, $items, $data, false);

            if (! $receipt) {
                throw ValidationException::withMessages(['items' => 'Isi jumlah setor minimal untuk satu ukuran.']);
            }

            ActivityLog::record('receipt', "Setor hasil {$receipt->number}: ".qty($receipt->quantity).' pcs bagus'.((float) $receipt->defect_quantity > 0 ? ', '.qty($receipt->defect_quantity).' pcs grade B' : '').' (HPP sementara '.rupiah($receipt->unit_cost).'/pcs)', $order);

            return $receipt;
        });
    }

    /**
     * Selesaikan produksi: setoran penutup untuk sisa hasil, lalu SPK ditutup dengan HPP aktual.
     *
     * @param  array<int|string, float|string|null>  $finished  production_order_item_id => qty bagus pada setoran penutup
     * @param  array<int|string, float|string|null>  $defects  production_order_item_id => qty reject grade B (masuk stok cacat)
     */
    public function complete(ProductionOrder $order, array $finished, float $reject = 0, array $defects = [], ?string $date = null): void
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

        DB::transaction(function () use ($order, $finished, $reject, $defects, $date) {
            $items = collect($order->items()->pluck('id'))->mapWithKeys(fn ($id) => [$id => [
                'good' => $finished[$id] ?? 0,
                'defect' => $defects[$id] ?? 0,
            ]])->all();

            $receipt = $this->createReceipt($order, $items, ['date' => $date ?? now()->toDateString()], true);
            $order->refresh();

            $good = (float) $order->items()->sum('finished_quantity');
            $defect = (float) $order->items()->sum('defect_quantity');

            if ($good <= 0) {
                throw ValidationException::withMessages(['finished' => 'Isi jumlah barang jadi minimal untuk satu ukuran.']);
            }

            $materialCost = round($order->materials()->get()->sum(fn ($line) => (float) $line->issued_quantity * (float) $line->unit_cost), 2);
            $conversionCost = round((float) $order->costs()->sum('amount'), 2);
            $totalCost = $materialCost + $conversionCost;

            $order->update([
                'status' => ProductionOrderStatus::Completed,
                'finished_quantity' => $good,
                'defect_quantity' => $defect,
                'reject_quantity' => max($reject, $defect),
                'material_cost' => $materialCost,
                'conversion_cost' => $conversionCost,
                'total_cost' => $totalCost,
                'unit_cost' => round($totalCost / ($good + $defect), 2),
                'completed_at' => now(),
            ]);

            $this->journal->productionComplete($order);

            ActivityLog::record('completed', 'Menyelesaikan produksi, total hasil '.qty($good).' pcs'.($defect > 0 ? ' + '.qty($defect).' pcs grade B' : '').', HPP rata-rata '.rupiah($order->unit_cost).'/pcs'.($receipt ? " (setoran penutup {$receipt->number})" : ''), $order);
        });
    }

    /**
     * Buat dokumen setoran hasil + mutasi stok + jurnal. Null bila tidak ada qty yang disetor.
     * Setoran bertahap memakai perkiraan HPP; setoran penutup menyerap sisa biaya SPK (biaya aktual − setoran sebelumnya).
     *
     * @param  array<int|string, array{good?: float|string|null, defect?: float|string|null}>  $items
     */
    private function createReceipt(ProductionOrder $order, array $items, array $data, bool $final): ?ProductionReceipt
    {
        $orderItems = $order->items()->with('variant')->lockForUpdate()->get()->keyBy('id');
        $lines = [];

        foreach ($items as $itemId => $qty) {
            $good = max(0, round((float) ($qty['good'] ?? 0), 2));
            $defect = max(0, round((float) ($qty['defect'] ?? 0), 2));

            if ($good <= 0 && $defect <= 0) {
                continue;
            }

            $item = $orderItems->get($itemId) ?? throw ValidationException::withMessages(['items' => 'Ukuran tidak terdaftar pada SPK ini.']);

            if ($good + $defect > $item->remainingQuantity() + 0.0001) {
                throw ValidationException::withMessages([
                    ($final ? 'finished.' : 'items.').$item->id => "Setoran {$item->variant->display_name} melebihi sisa rencana yang belum disetor (".qty($item->remainingQuantity()).' pcs).',
                ]);
            }

            $lines[$item->id] = [$item, $good, $defect];
        }

        if (empty($lines)) {
            return null;
        }

        $good = array_sum(array_column($lines, 1));
        $defect = array_sum(array_column($lines, 2));

        // Barang jadi bagus (kumulatif) tidak boleh melebihi output tahap terakhir (packing) bila sudah diisi
        $lastStage = $order->stages()->reorder('sequence', 'desc')->first();
        $goodTotal = (float) $orderItems->sum('finished_quantity') + $good;

        if ($lastStage && (float) $lastStage->output_quantity > 0 && $goodTotal > (float) $lastStage->output_quantity + 0.0001) {
            throw ValidationException::withMessages([
                $final ? 'finished' : 'items' => 'Total barang jadi ('.qty($goodTotal).' pcs) melebihi output tahap '.$lastStage->stage->label().' ('.qty($lastStage->output_quantity).' pcs). Perbarui output tahap atau sesuaikan jumlah per ukuran.',
            ]);
        }

        if ($final) {
            $materialCost = (float) $order->materials()->get()->sum(fn ($line) => (float) $line->issued_quantity * (float) $line->unit_cost);
            $actual = $materialCost + (float) $order->costs()->sum('amount');
            $received = (float) $order->receipts()->sum('total_cost');
            $unitCost = round(max(0, $actual - $received) / ($good + $defect), 2);
        } else {
            $unitCost = $this->estimatedUnitCost($order);
        }

        $date = Carbon::parse($data['date']);
        $receipt = $order->receipts()->create([
            'number' => DocumentNumber::generate(ProductionReceipt::class, 'HPJ', $date),
            'date' => $date,
            'warehouse_id' => Warehouse::resolveId($data['warehouse_id'] ?? $order->warehouse_id),
            'is_final' => $final,
            'quantity' => $good,
            'defect_quantity' => $defect,
            'unit_cost' => $unitCost,
            'total_cost' => round($unitCost * ($good + $defect), 2),
            'notes' => $data['notes'] ?? null,
            'created_by' => auth()->id(),
        ]);

        foreach ($lines as [$item, $itemGood, $itemDefect]) {
            $receipt->items()->create([
                'production_order_item_id' => $item->id,
                'product_variant_id' => $item->product_variant_id,
                'quantity' => $itemGood,
                'defect_quantity' => $itemDefect,
            ]);

            if ($itemGood > 0) {
                $this->stock->move(
                    $item->variant, $itemGood, StockMovementType::ProductionOutput, $receipt, $receipt->number,
                    "Hasil produksi {$order->number}", $date, unitCost: $unitCost, warehouse: $receipt->warehouse_id,
                );
            }

            if ($itemDefect > 0) {
                $this->stock->move(
                    $item->variant, $itemDefect, StockMovementType::ProductionOutput, $receipt, $receipt->number,
                    "Reject grade B {$order->number}", $date, unitCost: $unitCost, location: StockLocation::Defect, warehouse: $receipt->warehouse_id,
                );
            }

            $item->update([
                'finished_quantity' => (float) $item->finished_quantity + $itemGood,
                'defect_quantity' => (float) $item->defect_quantity + $itemDefect,
            ]);
        }

        $this->journal->productionReceipt($receipt);

        return $receipt;
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
