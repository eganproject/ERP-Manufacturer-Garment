<?php

namespace App\Services;

use App\Enums\DepreciationMethod;
use App\Enums\FixedAssetStatus;
use App\Enums\ReturnStatus;
use App\Models\ActivityLog;
use App\Models\AssetDepreciation;
use App\Models\DepreciationRun;
use App\Models\FixedAsset;
use App\Services\Accounting\AutoJournal;
use App\Services\Accounting\JournalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penyusutan aset tetap bulanan.
 *
 * - Garis lurus  : sisa nilai yang dapat disusutkan / sisa bulan masa manfaat (sama tiap bulan bila tanpa saldo awal).
 * - Saldo menurun: nilai buku awal tahun aset × (2 / masa manfaat dalam tahun) / 12; tidak melewati nilai sisa.
 * Bulan terakhir masa manfaat selalu menghabiskan sisa nilai sampai nilai sisa (residu).
 *
 * Posting per bulan (PNY-) membuat satu jurnal Dr Beban Penyusutan / Cr Akumulasi Penyusutan per aset.
 * Periode akuntansi yang sudah ditutup ditolak oleh {@see JournalService}.
 */
class DepreciationService
{
    public function __construct(private AutoJournal $journal, private JournalService $journals) {}

    /**
     * Penyusutan satu bulan.
     *
     * @param  callable(Carbon): float  $accumulatedBefore  akumulasi (termasuk saldo awal) sebelum bulan tsb
     */
    public function amountFor(FixedAsset $asset, Carbon $period, callable $accumulatedBefore): float
    {
        $life = (int) $asset->useful_life_months;
        $period = $period->copy()->startOfMonth();

        if ($life <= 0 || $period->lt($asset->depreciation_start->copy()->startOfMonth())) {
            return 0.0;
        }

        $index = (int) $asset->lifeStart()->diffInMonths($period) + 1;

        if ($index < 1 || $index > $life) {
            return 0.0;
        }

        $remaining = round($asset->depreciableBase() - $accumulatedBefore($period), 2);

        if ($remaining <= 0) {
            return 0.0;
        }

        if ($index === $life) {
            return $remaining;
        }

        if ($asset->depreciation_method === DepreciationMethod::DecliningBalance) {
            $yearStart = $asset->lifeStart()->addMonthsNoOverflow(intdiv($index - 1, 12) * 12);
            $bookAtYearStart = (float) $asset->cost - $accumulatedBefore($yearStart->max($asset->depreciation_start->copy()->startOfMonth()));

            return min($remaining, round(max(0, $bookAtYearStart) * 2 / $life, 2));
        }

        return round($remaining / ($life - $index + 1), 2);
    }

    /**
     * Jadwal penyusutan lengkap (proyeksi) dari bulan mulai susut sampai akhir masa manfaat.
     * Bulan yang sudah diposting memakai nilai sebenarnya.
     *
     * @return Collection<int, array{period: Carbon, amount: float, accumulated: float, book_value: float, posted: bool, run: DepreciationRun|null}>
     */
    public function schedule(FixedAsset $asset): Collection
    {
        if (! $asset->isDepreciable()) {
            return collect();
        }

        $posted = $asset->depreciations()->with('run')->get()->keyBy(fn ($d) => $d->period->format('Y-m'));
        $before = [];
        $accumulated = (float) $asset->opening_accumulated;
        $rows = collect();
        $period = $asset->depreciation_start->copy()->startOfMonth();
        $end = $asset->lifeEnd();
        $stop = $asset->disposal_date?->copy()->startOfMonth();

        while ($period->lte($end) && (! $stop || $period->lt($stop))) {
            $key = $period->format('Y-m');
            $before[$key] = $accumulated;
            $line = $posted->get($key);
            $amount = $line
                ? (float) $line->amount
                : $this->amountFor($asset, $period, fn (Carbon $p) => $before[$p->format('Y-m')] ?? (float) $asset->opening_accumulated);
            $accumulated = round($accumulated + $amount, 2);

            $rows->push([
                'period' => $period->copy(),
                'amount' => $amount,
                'accumulated' => $accumulated,
                'book_value' => round((float) $asset->cost - $accumulated, 2),
                'posted' => (bool) $line,
                'run' => $line?->run,
            ]);
            $period->addMonthNoOverflow();
        }

        return $rows;
    }

    /**
     * Penyusutan yang belum diposting s.d. bulan $upTo untuk satu aset (termasuk bulan yang terlewat).
     *
     * @return list<array{period: Carbon, amount: float}>
     */
    public function pendingFor(FixedAsset $asset, Carbon $upTo): array
    {
        if (! $asset->isActive() || ! $asset->isDepreciable()) {
            return [];
        }

        $upTo = $upTo->copy()->startOfMonth();
        $last = $asset->lastDepreciatedPeriod();
        $period = $last ? $last->copy()->addMonthNoOverflow() : $asset->depreciation_start->copy()->startOfMonth();
        $end = $asset->lifeEnd()->min($upTo);

        $sums = $asset->depreciations()->pluck('amount', 'period')
            ->mapWithKeys(fn ($amount, $p) => [Carbon::parse($p)->format('Y-m') => (float) $amount]);
        $opening = (float) $asset->opening_accumulated;
        $pending = [];

        while ($period->lte($end)) {
            $accumulatedBefore = function (Carbon $p) use ($sums, $opening) {
                $key = $p->format('Y-m');

                return round($opening + $sums->filter(fn ($amount, $k) => $k < $key)->sum(), 2);
            };
            $amount = $this->amountFor($asset, $period, $accumulatedBefore);

            if ($amount > 0) {
                $pending[] = ['period' => $period->copy(), 'amount' => $amount];
                $sums[$period->format('Y-m')] = $amount;
            }

            $period->addMonthNoOverflow();
        }

        return $pending;
    }

    /**
     * Pratinjau posting bulan $period: aset aktif beserta penyusutan yang akan diposting.
     *
     * @return Collection<int, array{asset: FixedAsset, lines: list<array{period: Carbon, amount: float}>, amount: float}>
     */
    public function preview(Carbon $period): Collection
    {
        return FixedAsset::active()->with('category')
            ->where('useful_life_months', '>', 0)
            ->whereDate('depreciation_start', '<=', $period->copy()->endOfMonth()->toDateString())
            ->orderBy('number')
            ->get()
            ->map(fn (FixedAsset $asset) => ['asset' => $asset, 'lines' => $lines = $this->pendingFor($asset, $period), 'amount' => round(array_sum(array_column($lines, 'amount')), 2)])
            ->filter(fn ($row) => $row['amount'] > 0)
            ->values();
    }

    /** Bulan terakhir yang sudah diposting penyusutannya. */
    public function lastPostedPeriod(): ?Carbon
    {
        $period = DepreciationRun::where('status', ReturnStatus::Posted)->max('period');

        return $period ? Carbon::parse($period)->startOfMonth() : null;
    }

    /** Posting penyusutan semua aset aktif s.d. bulan $period (jurnal bertanggal akhir bulan). */
    public function run(Carbon $period): DepreciationRun
    {
        $period = $period->copy()->startOfMonth();

        if ($period->gt(today()->startOfMonth())) {
            throw ValidationException::withMessages(['period' => 'Penyusutan hanya bisa diposting s.d. bulan berjalan.']);
        }

        if (($last = $this->lastPostedPeriod()) && $period->lt($last)) {
            throw ValidationException::withMessages(['period' => 'Penyusutan sudah diposting s.d. '.$last->translatedFormat('F Y').'. Pilih bulan tersebut atau sesudahnya.']);
        }

        return DB::transaction(function () use ($period) {
            $rows = $this->preview($period);

            if ($rows->isEmpty()) {
                throw ValidationException::withMessages(['period' => 'Tidak ada penyusutan yang perlu diposting s.d. '.$period->translatedFormat('F Y').'.']);
            }

            $date = $period->copy()->endOfMonth()->startOfDay();
            $run = DepreciationRun::create([
                'number' => DocumentNumber::generate(DepreciationRun::class, 'PNY', $date),
                'period' => $period,
                'date' => $date,
                'total' => round($rows->sum('amount'), 2),
                'asset_count' => $rows->count(),
                'status' => ReturnStatus::Posted,
                'created_by' => auth()->id(),
            ]);

            foreach ($rows as $row) {
                /** @var FixedAsset $asset */
                $asset = $row['asset'];
                $accumulated = (float) $asset->accumulated_depreciation;

                foreach ($row['lines'] as $line) {
                    $accumulated = round($accumulated + $line['amount'], 2);
                    $run->lines()->create([
                        'fixed_asset_id' => $asset->id,
                        'period' => $line['period'],
                        'amount' => $line['amount'],
                        'accumulated' => $accumulated,
                        'book_value' => round((float) $asset->cost - $accumulated, 2),
                    ]);
                }

                $asset->update(['accumulated_depreciation' => $accumulated]);
            }

            $this->journal->depreciationRun($run);

            ActivityLog::record('created', "Memposting penyusutan {$run->periodLabel()} ({$run->asset_count} aset, ".rupiah($run->total).')', $run);

            return $run;
        });
    }

    /** Batalkan posting penyusutan terakhir (jurnal dibalik pada tanggal yang sama). */
    public function cancel(DepreciationRun $run, string $reason): void
    {
        if ($run->isCancelled()) {
            throw ValidationException::withMessages(['status' => 'Posting penyusutan ini sudah dibatalkan.']);
        }

        $latest = DepreciationRun::where('status', ReturnStatus::Posted)->orderByDesc('period')->orderByDesc('id')->first();

        if ($latest && $latest->id !== $run->id) {
            throw ValidationException::withMessages(['status' => "Batalkan dulu posting penyusutan yang lebih baru ({$latest->number})."]);
        }

        $assetIds = $run->lines()->pluck('fixed_asset_id')->unique();

        if (FixedAsset::whereIn('id', $assetIds)->where('status', '!=', FixedAssetStatus::Active)->exists()) {
            throw ValidationException::withMessages(['status' => 'Sebagian aset pada posting ini sudah dilepas. Batalkan pelepasannya terlebih dulu.']);
        }

        DB::transaction(function () use ($run, $reason, $assetIds) {
            $this->journals->reverseSource($run, null, $run->date, "penyusutan dibatalkan – {$reason}");
            $run->lines()->delete();
            $run->update(['status' => ReturnStatus::Cancelled, 'cancel_reason' => $reason]);

            foreach (FixedAsset::whereIn('id', $assetIds)->get() as $asset) {
                $this->refreshAccumulated($asset);
            }

            ActivityLog::record('cancelled', "Membatalkan penyusutan {$run->periodLabel()}: {$reason}", $run);
        });
    }

    public function refreshAccumulated(FixedAsset $asset): void
    {
        $asset->update([
            'accumulated_depreciation' => round((float) $asset->opening_accumulated + (float) AssetDepreciation::where('fixed_asset_id', $asset->id)->sum('amount'), 2),
        ]);
    }
}
