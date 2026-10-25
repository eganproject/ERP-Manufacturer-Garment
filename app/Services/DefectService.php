<?php

namespace App\Services;

use App\Enums\DefectHandlingType;
use App\Enums\ReturnStatus;
use App\Enums\StockLocation;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\DefectHandling;
use App\Models\ProductVariant;
use App\Services\Accounting\AutoJournal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Barang cacat (karantina). Barang jadi cacat disimpan di stok cacat varian (defect_stock), terpisah
 * dari stok jual, dengan harga pokok rata-rata sendiri. Sumbernya retur penjualan berkondisi cacat
 * atau temuan di gudang (Pindah ke Stok Cacat). Penanganannya lewat dokumen BC-:
 *
 * - Perbaiki (rework): stok cacat → stok jual; biaya perbaikan menambah HPP (Cr Kas/Bank).
 * - Kembalikan ke stok jual: stok cacat → stok jual tanpa biaya.
 * - Musnahkan (scrap): stok cacat keluar, nilainya jadi Beban Barang Cacat.
 */
class DefectService
{
    public function __construct(private StockService $stock, private AutoJournal $journal) {}

    /**
     * @param  array{date: string, notes?: string|null, extra_cost?: float|string|null, cash_account_id?: int|string|null}  $data
     * @param  list<array{product_variant_id: int|string|null, quantity: float|string|null}>  $lines
     */
    public function record(DefectHandlingType $type, array $data, array $lines): DefectHandling
    {
        $lines = $this->normalizeLines($lines);
        $extra = $type === DefectHandlingType::Rework ? round(max(0, (float) ($data['extra_cost'] ?? 0)), 2) : 0.0;
        $cashAccountId = null;

        if ($extra > 0) {
            $cashAccountId = Account::cash()->where('is_active', true)->whereKey($data['cash_account_id'] ?? null)->value('id');

            if (! $cashAccountId) {
                throw ValidationException::withMessages(['cash_account_id' => 'Pilih rekening kas/bank untuk biaya perbaikan.']);
            }
        }

        return DB::transaction(function () use ($type, $data, $lines, $extra, $cashAccountId) {
            $date = Carbon::parse($data['date']);

            $handling = DefectHandling::create([
                'number' => DocumentNumber::generate(DefectHandling::class, 'BC', $date),
                'date' => $date,
                'type' => $type,
                'status' => ReturnStatus::Posted,
                'extra_cost' => $extra,
                'cash_account_id' => $cashAccountId,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $totalQty = array_sum(array_column($lines, 'quantity'));
            $allocated = 0.0;
            $movementType = $type->stockMovementType();
            $from = $type->fromDefect() ? StockLocation::Defect : StockLocation::Main;
            $note = $type->label().($handling->notes ? ' – '.$handling->notes : '');

            foreach (array_values($lines) as $i => $line) {
                $variant = ProductVariant::with('product')->findOrFail($line['product_variant_id']);
                $qty = $line['quantity'];

                $out = $this->stock->move($variant, -$qty, $movementType, $handling, $handling->number, $note, $date, location: $from);
                $unitCost = (float) $out->unit_cost;

                // Biaya perbaikan dibagi rata per pcs; sisa pembulatan di baris terakhir
                $lineExtra = $extra > 0
                    ? ($i === count($lines) - 1 ? round($extra - $allocated, 2) : round($extra * $qty / $totalQty, 2))
                    : 0.0;
                $allocated += $lineExtra;

                if ($type !== DefectHandlingType::Scrap) {
                    $to = $from === StockLocation::Defect ? StockLocation::Main : StockLocation::Defect;
                    $this->stock->move($variant, $qty, $movementType, $handling, $handling->number, $note, $date,
                        unitCost: $unitCost + $lineExtra / $qty, location: $to);
                }

                $handling->items()->create([
                    'product_variant_id' => $variant->id,
                    'quantity' => $qty,
                    'unit_cost' => $unitCost,
                    'cost' => round($qty * $unitCost, 2),
                    'extra_cost' => $lineExtra,
                ]);
            }

            $handling->update(['total_cost' => round((float) $handling->items()->sum('cost'), 2)]);
            $this->journal->defectHandling($handling);

            ActivityLog::record('created', "Mencatat {$type->label()} {$handling->number} (".qty($totalQty).' pcs)', $handling);

            return $handling;
        });
    }

    /** Batalkan: stok dikembalikan ke lokasi semula dan jurnal dibalik. */
    public function cancel(DefectHandling $handling, string $reason): void
    {
        if ($handling->isCancelled()) {
            throw ValidationException::withMessages(['status' => 'Dokumen ini sudah dibatalkan.']);
        }

        DB::transaction(function () use ($handling, $reason) {
            $type = $handling->type;
            $from = $type->fromDefect() ? StockLocation::Defect : StockLocation::Main;
            $to = $from === StockLocation::Defect ? StockLocation::Main : StockLocation::Defect;
            $note = 'Pembatalan '.$handling->number;
            $date = today();

            foreach ($handling->items()->with('variant')->get() as $item) {
                $qty = (float) $item->quantity;

                if ($type !== DefectHandlingType::Scrap) {
                    $this->stock->move($item->variant, -$qty, $type->stockMovementType(), $handling, $handling->number, $note, $date, location: $to);
                }

                $this->stock->move($item->variant, $qty, $type->stockMovementType(), $handling, $handling->number, $note, $date,
                    unitCost: (float) $item->unit_cost > 0 ? (float) $item->unit_cost : null, location: $from);
            }

            $handling->update(['status' => ReturnStatus::Cancelled, 'cancel_reason' => $reason]);
            $this->journal->reverse($handling, "{$type->label()} dibatalkan – {$reason}");

            ActivityLog::record('cancelled', "Membatalkan {$type->label()}: {$reason}", $handling);
        });
    }

    /**
     * @return list<array{product_variant_id: int, quantity: float}>
     */
    private function normalizeLines(array $lines): array
    {
        $rows = [];

        foreach (array_values($lines) as $i => $line) {
            $qty = round((float) ($line['quantity'] ?? 0), 2);
            $id = (int) ($line['product_variant_id'] ?? 0);

            if (! $id && $qty <= 0) {
                continue;
            }
            if (! $id || ! ProductVariant::whereKey($id)->exists()) {
                throw ValidationException::withMessages(["lines.{$i}.product_variant_id" => 'Baris '.($i + 1).': pilih produk.']);
            }
            if ($qty <= 0) {
                throw ValidationException::withMessages(["lines.{$i}.quantity" => 'Baris '.($i + 1).': jumlah harus lebih dari 0.']);
            }

            // Varian yang sama digabung agar pengecekan stok benar
            $rows[$id] = ['product_variant_id' => $id, 'quantity' => round(($rows[$id]['quantity'] ?? 0) + $qty, 2)];
        }

        if (empty($rows)) {
            throw ValidationException::withMessages(['lines' => 'Isi minimal satu produk & jumlah.']);
        }

        return array_values($rows);
    }
}
