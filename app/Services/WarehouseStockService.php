<?php

namespace App\Services;

use App\Enums\ReturnStatus;
use App\Enums\StockMovementType;
use App\Enums\StockOpnameStatus;
use App\Models\ActivityLog;
use App\Models\Material;
use App\Models\ProductVariant;
use App\Models\StockOpname;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Services\Accounting\AutoJournal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Multi-gudang: transfer antar gudang (TG-) dan stock opname per gudang (OPN-).
 * Semua mutasi lewat {@see StockService::move()} dengan gudangnya.
 */
class WarehouseStockService
{
    public function __construct(private StockService $stock, private AutoJournal $journal) {}

    /** "material:5" / "variant:7" → model barang. */
    public static function findItem(string $key): Material|ProductVariant
    {
        if (! preg_match('/^(material|variant):(\d+)$/', $key, $m)) {
            throw ValidationException::withMessages(['items' => 'Barang tidak valid.']);
        }

        return $m[1] === 'material' ? Material::findOrFail($m[2]) : ProductVariant::with('product')->findOrFail($m[2]);
    }

    public static function itemName(Material|ProductVariant $item): string
    {
        return $item instanceof Material ? $item->display_name : $item->sku.' · '.$item->display_name;
    }

    /**
     * @param  array{date: string, from_warehouse_id: int|string, to_warehouse_id: int|string, notes?: string|null}  $data
     * @param  list<array{item: string|null, quantity: float|string|null}>  $lines
     */
    public function transfer(array $data, array $lines): StockTransfer
    {
        $from = Warehouse::resolveId($data['from_warehouse_id'] ?? null);
        $to = Warehouse::resolveId($data['to_warehouse_id'] ?? null);

        if ($from === $to) {
            throw ValidationException::withMessages(['to_warehouse_id' => 'Gudang tujuan harus berbeda dengan gudang asal.']);
        }

        $rows = [];
        foreach (array_values($lines) as $i => $line) {
            $qty = round((float) ($line['quantity'] ?? 0), 2);
            if (empty($line['item']) && $qty <= 0) {
                continue;
            }
            if (empty($line['item']) || $qty <= 0) {
                throw ValidationException::withMessages(["lines.{$i}.quantity" => 'Baris '.($i + 1).': pilih barang dan isi jumlah lebih dari 0.']);
            }
            $rows[$line['item']] = round(($rows[$line['item']] ?? 0) + $qty, 2);
        }

        if (empty($rows)) {
            throw ValidationException::withMessages(['lines' => 'Isi minimal satu barang & jumlah.']);
        }

        return DB::transaction(function () use ($data, $from, $to, $rows) {
            $date = Carbon::parse($data['date']);
            $transfer = StockTransfer::create([
                'number' => DocumentNumber::generate(StockTransfer::class, 'TG', $date),
                'date' => $date,
                'from_warehouse_id' => $from,
                'to_warehouse_id' => $to,
                'status' => ReturnStatus::Posted,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);
            $transfer->load('fromWarehouse', 'toWarehouse');

            foreach ($rows as $key => $qty) {
                $item = self::findItem($key);
                $out = $this->stock->move($item, -$qty, StockMovementType::Transfer, $transfer, $transfer->number,
                    "Transfer ke {$transfer->toWarehouse->name}", $date, warehouse: $from);
                $this->stock->move($item, $qty, StockMovementType::Transfer, $transfer, $transfer->number,
                    "Transfer dari {$transfer->fromWarehouse->name}", $date, warehouse: $to);

                $transfer->items()->create([
                    'stockable_type' => $item->getMorphClass(),
                    'stockable_id' => $item->getKey(),
                    'quantity' => $qty,
                    'unit_cost' => (float) $out->unit_cost,
                ]);
            }

            ActivityLog::record('created', "Transfer {$transfer->number} dari {$transfer->fromWarehouse->name} ke {$transfer->toWarehouse->name} (".count($rows).' barang)', $transfer);

            return $transfer;
        });
    }

    /** Batalkan transfer: barang dikembalikan ke gudang asal (stok di gudang tujuan harus cukup). */
    public function cancelTransfer(StockTransfer $transfer, string $reason): void
    {
        if ($transfer->isCancelled()) {
            throw ValidationException::withMessages(['status' => 'Transfer ini sudah dibatalkan.']);
        }

        DB::transaction(function () use ($transfer, $reason) {
            $date = today();
            $note = "Pembatalan {$transfer->number}";

            foreach ($transfer->items()->with('stockable')->get() as $line) {
                $this->stock->move($line->stockable, -(float) $line->quantity, StockMovementType::Transfer, $transfer, $transfer->number, $note, $date, warehouse: $transfer->to_warehouse_id);
                $this->stock->move($line->stockable, (float) $line->quantity, StockMovementType::Transfer, $transfer, $transfer->number, $note, $date, warehouse: $transfer->from_warehouse_id);
            }

            $transfer->update(['status' => ReturnStatus::Cancelled, 'cancel_reason' => $reason]);
            ActivityLog::record('cancelled', "Membatalkan transfer: {$reason}", $transfer);
        });
    }

    /**
     * Buat lembar stock opname (draft) berisi barang beserta stok sistem di gudang tsb.
     *
     * @param  array{warehouse_id: int|string|null, date: string, scope: string, category_id?: int|string|null, only_in_stock?: bool, notes?: string|null}  $data
     */
    public function createOpname(array $data): StockOpname
    {
        $warehouseId = Warehouse::resolveId($data['warehouse_id'] ?? null);
        $scope = $data['scope'];

        if (StockOpname::where('warehouse_id', $warehouseId)->where('scope', $scope)->where('status', StockOpnameStatus::Draft)->exists()) {
            throw ValidationException::withMessages(['warehouse_id' => 'Masih ada stock opname draft untuk gudang & jenis barang ini. Selesaikan atau batalkan dulu.']);
        }

        $items = $scope === 'materials'
            ? Material::where('is_active', true)->when($data['category_id'] ?? null, fn ($q, $c) => $q->where('category_id', $c))->with('warehouseStocks')->orderBy('name')->get()
            : ProductVariant::with('product', 'warehouseStocks')->whereHas('product', fn ($q) => $q->where('is_active', true)
                ->when($data['category_id'] ?? null, fn ($w, $c) => $w->where('category_id', $c)))->orderBy('product_id')->orderBy('id')->get();

        if (! empty($data['only_in_stock'])) {
            $items = $items->filter(fn ($i) => $i->stockIn($warehouseId) != 0);
        }

        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['scope' => 'Tidak ada barang untuk dihitung dengan filter ini.']);
        }

        return DB::transaction(function () use ($data, $warehouseId, $scope, $items) {
            $date = Carbon::parse($data['date']);
            $opname = StockOpname::create([
                'number' => DocumentNumber::generate(StockOpname::class, 'OPN', $date),
                'warehouse_id' => $warehouseId,
                'date' => $date,
                'scope' => $scope,
                'status' => StockOpnameStatus::Draft,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $opname->items()->createMany($items->map(fn ($item) => [
                'stockable_type' => $item->getMorphClass(),
                'stockable_id' => $item->getKey(),
                'system_quantity' => $item->stockIn($warehouseId),
                'unit_cost' => $this->unitCost($item),
            ])->values()->all());

            ActivityLog::record('created', "Membuat lembar stock opname {$opname->number} ({$opname->scopeLabel()}, ".$items->count().' barang)', $opname);

            return $opname;
        });
    }

    /**
     * Simpan hasil hitung fisik (kosong = belum dihitung).
     *
     * @param  array<int, float|string|null>  $counts  stock_opname_item_id => qty fisik
     * @param  array<int, string|null>  $notes
     */
    public function saveCounts(StockOpname $opname, array $counts, array $notes = []): void
    {
        $this->ensureDraft($opname);

        DB::transaction(function () use ($opname, $counts, $notes) {
            foreach ($opname->items()->get() as $line) {
                if (! array_key_exists($line->id, $counts)) {
                    continue;
                }

                $value = $counts[$line->id];
                $counted = ($value === null || $value === '') ? null : round((float) $value, 2);

                if ($counted !== null && $counted < 0) {
                    throw ValidationException::withMessages(["counts.{$line->id}" => 'Jumlah fisik tidak boleh negatif.']);
                }

                $line->update([
                    'counted_quantity' => $counted,
                    'difference' => $counted === null ? 0 : round($counted - (float) $line->system_quantity, 2),
                    'notes' => $notes[$line->id] ?? $line->notes,
                ]);
            }
        });
    }

    /**
     * Posting: selisih dihitung terhadap stok gudang saat posting (mutasi setelah lembar dibuat ikut
     * diperhitungkan), lalu dicatat sebagai penyesuaian stok + jurnal selisih persediaan.
     */
    public function postOpname(StockOpname $opname): void
    {
        $this->ensureDraft($opname);

        $lines = $opname->items()->with('stockable')->whereNotNull('counted_quantity')->get();

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['counts' => 'Isi minimal satu hasil hitung fisik sebelum posting.']);
        }

        DB::transaction(function () use ($opname, $lines) {
            $adjusted = 0;

            foreach ($lines as $line) {
                $item = $line->stockable;
                $system = $item->stockIn($opname->warehouse_id);
                $diff = round((float) $line->counted_quantity - $system, 2);
                $unitCost = $this->unitCost($item);

                $line->update([
                    'system_quantity' => $system,
                    'difference' => $diff,
                    'unit_cost' => $unitCost,
                    'value' => round($diff * $unitCost, 2),
                ]);

                if (abs($diff) >= 0.005) {
                    $movement = $this->stock->move($item, $diff, StockMovementType::Adjustment, $opname, $opname->number,
                        "Stock opname {$opname->number}".($line->notes ? " – {$line->notes}" : ''), $opname->date, warehouse: $opname->warehouse_id);
                    $this->journal->stockAdjustment($movement);
                    $adjusted++;
                }
            }

            $opname->update(['status' => StockOpnameStatus::Posted, 'posted_at' => now(), 'posted_by' => auth()->id()]);
            ActivityLog::record('posted', "Memposting stock opname: {$adjusted} barang disesuaikan, selisih ".rupiah($opname->items()->sum('value')), $opname);
        });
    }

    public function cancelOpname(StockOpname $opname, ?string $reason = null): void
    {
        $this->ensureDraft($opname);
        $opname->update(['status' => StockOpnameStatus::Cancelled, 'cancel_reason' => $reason]);
        ActivityLog::record('cancelled', 'Membatalkan stock opname'.($reason ? ": {$reason}" : ''), $opname);
    }

    private function ensureDraft(StockOpname $opname): void
    {
        if (! $opname->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Stock opname ini sudah diposting / dibatalkan.']);
        }
    }

    private function unitCost(Material|ProductVariant $item): float
    {
        return $item instanceof Material ? $item->costPrice() : (float) $item->average_cost;
    }
}
