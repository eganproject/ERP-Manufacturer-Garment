<?php

namespace App\Services;

use App\Enums\ProductionOrderStatus;
use App\Enums\ProductionStage;
use App\Enums\StageStatus;
use App\Enums\StockMovementType;
use App\Models\Bom;
use App\Models\ProductionOrder;
use App\Models\ProductionStage as Stage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionService
{
    public function __construct(private StockService $stock) {}

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
     */
    public function start(ProductionOrder $order): void
    {
        if ($order->status !== ProductionOrderStatus::Draft) {
            throw ValidationException::withMessages(['status' => 'Hanya SPK berstatus Draft yang dapat dimulai.']);
        }

        DB::transaction(function () use ($order) {
            foreach ($order->materials()->with('material')->get() as $line) {
                $this->stock->move(
                    $line->material, -1 * (float) $line->required_quantity, StockMovementType::ProductionIssue,
                    $order, $order->number, 'Pengeluaran bahan produksi',
                );
                $line->update(['issued_quantity' => $line->required_quantity]);
            }

            $order->update([
                'status' => ProductionOrderStatus::InProgress,
                'materials_issued_at' => now(),
            ]);
        });
    }

    public function updateStage(Stage $stage, array $data): void
    {
        $order = $stage->productionOrder;

        if ($order->status !== ProductionOrderStatus::InProgress) {
            throw ValidationException::withMessages(['status' => 'Tahapan hanya dapat diperbarui saat SPK Dalam Proses.']);
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

                if ($qty > 0) {
                    $this->stock->move(
                        $item->variant, $qty, StockMovementType::ProductionOutput,
                        $order, $order->number, 'Hasil produksi',
                    );
                }
            }

            $order->update([
                'status' => ProductionOrderStatus::Completed,
                'finished_quantity' => $total,
                'reject_quantity' => $reject,
                'completed_at' => now(),
            ]);
        });
    }
}
