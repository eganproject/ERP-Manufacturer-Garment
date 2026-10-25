<?php

namespace App\Services;

use App\Enums\ProductionCostType;
use App\Enums\ProductionOrderStatus;
use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\PieceworkEntry;
use App\Models\PieceworkSheet;
use App\Models\ProductionOrder;
use App\Services\Accounting\AutoJournal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Hasil kerja borongan harian karyawan produksi.
 *
 * Setiap lembar hasil kerja (HKB-) untuk satu SPK berisi baris per karyawan per operasi/segmen
 * (atau "pcs jadi" untuk karyawan yang mengerjakan satu baju dari awal sampai jadi).
 * Upah = hasil bagus × tarif; reject QC dicatat tetapi tidak dibayar. Total upah lembar menjadi
 * biaya produksi tipe upah pada SPK (masuk HPP aktual) dan dijurnal Dr WIP / Cr Hutang upah.
 *
 * Batas jumlah per operasi = jumlah SPK dikurangi pcs yang tahapnya dimaklonkan ke vendor.
 * Satu pcs jadi dihitung mengerjakan semua operasi artikel tersebut.
 */
class PieceworkService
{
    public const FULL = 'full';

    public function __construct(private AutoJournal $journal) {}

    /**
     * Kapasitas dan progres borongan per operasi SPK.
     *
     * @return Collection<string, array{key: string, name: string, stage: ?string, rate: float, capacity: float, good: float, reject: float, used: float, remaining: float}>
     */
    public function progress(ProductionOrder $spk): Collection
    {
        $spk->loadMissing(['product.operations', 'stages.subcontractOrder']);

        $operations = $spk->product->operations->where('is_active', true);
        $entries = PieceworkEntry::whereHas('sheet', fn ($q) => $q->where('production_order_id', $spk->id))->get();
        $full = $entries->whereNull('product_operation_id');
        $fullUsed = (float) $full->sum('quantity') + (float) $full->sum('reject_quantity');

        // Pcs yang tahapnya dikerjakan vendor maklon tidak dikerjakan karyawan internal
        $subcontracted = $spk->stages
            ->filter(fn ($stage) => $stage->subcontractOrder !== null)
            ->mapWithKeys(fn ($stage) => [$stage->stage->value => (float) $stage->subcontractOrder->quantity]);

        $planned = (float) $spk->quantity;
        $rows = collect();

        foreach ($operations as $op) {
            $own = $entries->where('product_operation_id', $op->id);
            $good = (float) $own->sum('quantity') + (float) $full->sum('quantity');
            $reject = (float) $own->sum('reject_quantity') + (float) $full->sum('reject_quantity');
            $capacity = max(0, $planned - ($subcontracted[$op->stage->value] ?? 0));

            $rows[(string) $op->id] = [
                'key' => (string) $op->id,
                'name' => $op->name,
                'stage' => $op->stage->label(),
                'rate' => (float) $op->rate,
                'capacity' => $capacity,
                'good' => $good,
                'reject' => $reject,
                'used' => $good + $reject,
                'remaining' => round(max(0, $capacity - $good - $reject), 2),
            ];
        }

        $fullCapacity = max(0, $planned - (float) ($operations->isEmpty()
            ? $subcontracted->max() ?? 0
            : $operations->map(fn ($op) => $subcontracted[$op->stage->value] ?? 0)->max()));

        $rows[self::FULL] = [
            'key' => self::FULL,
            'name' => 'Pcs jadi (komplit)',
            'stage' => null,
            'rate' => (float) $spk->product->full_garment_rate,
            'capacity' => $fullCapacity,
            'good' => (float) $full->sum('quantity'),
            'reject' => (float) $full->sum('reject_quantity'),
            'used' => $fullUsed,
            // Pcs jadi butuh semua operasi masih tersisa
            'remaining' => round($operations->isEmpty()
                ? max(0, $fullCapacity - $fullUsed)
                : (float) $rows->except(self::FULL)->min('remaining'), 2),
        ];

        return $rows;
    }

    /**
     * Catat lembar hasil kerja harian.
     *
     * @param  array{date: string, notes?: string|null}  $data
     * @param  list<array{employee_id: int|string, operation: string, quantity?: float|string|null, reject_quantity?: float|string|null}>  $lines  operation = id operasi atau "full"
     */
    public function record(ProductionOrder $spk, array $data, array $lines): PieceworkSheet
    {
        if ($spk->status !== ProductionOrderStatus::InProgress) {
            throw ValidationException::withMessages(['production_order_id' => 'Hasil kerja hanya dapat dicatat untuk SPK yang sedang Dalam Proses.']);
        }

        $date = Carbon::parse($data['date']);

        if ($date->lt($spk->start_date) || $date->gt(today())) {
            throw ValidationException::withMessages(['date' => 'Tanggal hasil kerja harus antara tanggal mulai SPK ('.tanggal($spk->start_date).') dan hari ini.']);
        }

        $lines = collect($lines)
            ->map(fn ($l) => [
                'employee_id' => (int) ($l['employee_id'] ?? 0),
                'operation' => (string) ($l['operation'] ?? ''),
                'quantity' => round(max(0, (float) ($l['quantity'] ?? 0)), 2),
                'reject_quantity' => round(max(0, (float) ($l['reject_quantity'] ?? 0)), 2),
            ])
            ->filter(fn ($l) => $l['employee_id'] && $l['operation'] !== '' && ($l['quantity'] + $l['reject_quantity']) > 0)
            ->values();

        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'Isi minimal satu baris hasil kerja (karyawan, operasi, dan jumlah).']);
        }

        return DB::transaction(function () use ($spk, $data, $date, $lines) {
            $spk = ProductionOrder::lockForUpdate()->with(['product.operations', 'stages.subcontractOrder'])->findOrFail($spk->id);
            $operations = $spk->product->operations->keyBy('id');
            $employees = Employee::whereIn('id', $lines->pluck('employee_id'))->get()->keyBy('id');
            $progress = $this->progress($spk);
            $rows = [];

            foreach ($lines as $i => $line) {
                $field = "lines.{$i}";
                $employee = $employees[$line['employee_id']] ?? null;

                if (! $employee || ! $employee->is_active) {
                    throw ValidationException::withMessages(["{$field}.employee_id" => 'Baris '.($i + 1).': karyawan tidak ditemukan atau nonaktif.']);
                }

                if ($line['operation'] === self::FULL) {
                    if (! $employee->work_type->allowsFull()) {
                        throw ValidationException::withMessages(["{$field}.operation" => 'Baris '.($i + 1).": {$employee->name} dibayar per operasi, bukan per pcs jadi."]);
                    }

                    $rate = (float) $spk->product->full_garment_rate;

                    if ($rate <= 0) {
                        throw ValidationException::withMessages(["{$field}.operation" => "Tarif borongan pcs jadi untuk {$spk->product->name} belum diatur."]);
                    }

                    $operationId = null;
                    $name = 'Pcs jadi (komplit)';
                } else {
                    $operation = $operations[(int) $line['operation']] ?? null;

                    if (! $operation || ! $operation->is_active) {
                        throw ValidationException::withMessages(["{$field}.operation" => 'Baris '.($i + 1).': operasi tidak sesuai dengan artikel SPK.']);
                    }

                    if (! $employee->work_type->allowsOperation()) {
                        throw ValidationException::withMessages(["{$field}.operation" => 'Baris '.($i + 1).": {$employee->name} dibayar per pcs jadi, bukan per operasi."]);
                    }

                    $rate = (float) $operation->rate;
                    $operationId = $operation->id;
                    $name = $operation->name;
                }

                $rows[] = [
                    'employee_id' => $employee->id,
                    'product_operation_id' => $operationId,
                    'operation_name' => $name,
                    'quantity' => $line['quantity'],
                    'reject_quantity' => $line['reject_quantity'],
                    'rate' => $rate,
                    'amount' => round($line['quantity'] * $rate, 2),
                ];
            }

            $this->assertCapacity($progress, collect($rows));

            $sheet = PieceworkSheet::create([
                'number' => DocumentNumber::generate(PieceworkSheet::class, 'HKB', $date),
                'production_order_id' => $spk->id,
                'date' => $date,
                'notes' => $data['notes'] ?? null,
                'total_amount' => round(array_sum(array_column($rows, 'amount')), 2),
                'created_by' => auth()->id(),
            ]);
            $sheet->entries()->createMany($rows);

            if ((float) $sheet->total_amount > 0) {
                $pcs = array_sum(array_column($rows, 'quantity'));
                $cost = $spk->costs()->create([
                    'piecework_sheet_id' => $sheet->id,
                    'type' => ProductionCostType::Labor,
                    'description' => "Upah borongan {$sheet->number} (".collect($rows)->pluck('employee_id')->unique()->count().' karyawan, '.qty($pcs).' pcs/operasi)',
                    'amount' => $sheet->total_amount,
                    'date' => $date,
                    'created_by' => auth()->id(),
                ]);
                $this->journal->productionCost($cost);
            }

            ActivityLog::record('piecework', "Mencatat hasil kerja borongan {$sheet->number} tanggal ".tanggal($date).', upah '.rupiah($sheet->total_amount), $spk);

            return $sheet;
        });
    }

    /** Hapus lembar hasil kerja yang belum masuk penggajian (biaya & jurnal upah ikut dibatalkan). */
    public function delete(PieceworkSheet $sheet): void
    {
        $sheet->load(['entries', 'productionOrder', 'cost']);
        $spk = $sheet->productionOrder;

        if ($spk->status !== ProductionOrderStatus::InProgress) {
            throw ValidationException::withMessages(['status' => 'Hasil kerja SPK yang sudah selesai tidak dapat dihapus.']);
        }

        if ($sheet->isLocked()) {
            throw ValidationException::withMessages(['status' => 'Sebagian hasil kerja sudah masuk penggajian. Batalkan penggajiannya terlebih dahulu.']);
        }

        DB::transaction(function () use ($sheet, $spk) {
            if ($cost = $sheet->cost) {
                $this->journal->reverse($spk, "hasil kerja {$sheet->number} dihapus", AutoJournal::costEvent($cost));
                $cost->delete();
            }

            $sheet->delete();
            ActivityLog::record('piecework', "Menghapus hasil kerja borongan {$sheet->number}, upah ".rupiah($sheet->total_amount), $spk);
        });
    }

    /**
     * @param  Collection<string, array>  $progress
     * @param  Collection<int, array>  $rows
     */
    private function assertCapacity(Collection $progress, Collection $rows): void
    {
        $fullNew = (float) $rows->whereNull('product_operation_id')->sum(fn ($r) => $r['quantity'] + $r['reject_quantity']);
        $operations = $progress->except(self::FULL);

        if ($operations->isEmpty()) {
            $full = $progress[self::FULL];

            if ($fullNew > $full['remaining'] + 0.001) {
                throw ValidationException::withMessages(['lines' => 'Jumlah pcs jadi ('.qty($fullNew).') melebihi sisa SPK yang dikerjakan internal ('.qty($full['remaining']).' pcs).']);
            }

            return;
        }

        foreach ($operations as $key => $op) {
            $new = (float) $rows->where('product_operation_id', (int) $key)->sum(fn ($r) => $r['quantity'] + $r['reject_quantity']) + $fullNew;

            if ($new > $op['remaining'] + 0.001) {
                throw ValidationException::withMessages([
                    'lines' => "Operasi {$op['name']}: hasil ".qty($new).' pcs (termasuk pcs jadi) melebihi sisa '.qty($op['remaining']).' dari '.qty($op['capacity']).' pcs yang dikerjakan internal.',
                ]);
            }
        }
    }
}
