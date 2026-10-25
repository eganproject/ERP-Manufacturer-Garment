<?php

namespace App\Services\Accounting;

use App\Models\AccountingPeriod;
use App\Models\ActivityLog;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tutup buku bulanan. Periode ditutup berurutan; setelah ditutup tidak ada jurnal (otomatis maupun manual)
 * yang boleh bertanggal di periode tersebut. Hanya periode tertutup terakhir yang bisa dibuka kembali.
 */
class PeriodService
{
    /**
     * Daftar bulan dari jurnal pertama (atau awal tahun ini) sampai bulan berjalan, terbaru di atas.
     *
     * @return Collection<int, AccountingPeriod>
     */
    public function periods(): Collection
    {
        $first = JournalEntry::min('date');
        $start = Carbon::parse($first ?? today()->startOfYear())->startOfMonth();
        $earliestStored = AccountingPeriod::orderBy('year')->orderBy('month')->first();
        if ($earliestStored && $earliestStored->start()->lt($start)) {
            $start = $earliestStored->start();
        }

        $stored = AccountingPeriod::with('closer')->get()->keyBy(fn ($p) => $p->year.'-'.$p->month);
        $periods = collect();

        for ($month = today()->startOfMonth(); $month->gte($start); $month = $month->copy()->subMonthNoOverflow()) {
            $periods->push($stored->get($month->year.'-'.$month->month) ?? new AccountingPeriod(['year' => $month->year, 'month' => $month->month]));
        }

        return $periods;
    }

    public function close(int $year, int $month, User $user, ?string $notes = null): AccountingPeriod
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();

        if ($start->gt(today())) {
            throw ValidationException::withMessages(['period' => 'Periode yang belum berjalan tidak dapat ditutup.']);
        }

        return DB::transaction(function () use ($start, $year, $month, $user, $notes) {
            $period = AccountingPeriod::lockForUpdate()->firstOrNew(['year' => $year, 'month' => $month]);

            if ($period->isClosed()) {
                throw ValidationException::withMessages(['period' => "Periode {$period->label()} sudah ditutup."]);
            }

            // Bulan-bulan sebelumnya yang memiliki jurnal harus sudah ditutup lebih dulu
            $lockedUntil = AccountingPeriod::lockedUntil();
            $gapFrom = $lockedUntil ? $lockedUntil->copy()->addDay() : null;
            $openBefore = JournalEntry::whereDate('date', '<', $start->toDateString())
                ->when($gapFrom, fn ($q) => $q->whereDate('date', '>=', $gapFrom->toDateString()))
                ->min('date');

            if ($openBefore) {
                throw ValidationException::withMessages(['period' => 'Tutup periode '.Carbon::parse($openBefore)->translatedFormat('F Y').' terlebih dahulu (tutup buku harus berurutan).']);
            }

            $period->fill(['closed_at' => now(), 'closed_by' => $user->id, 'notes' => $notes ?: null])->save();

            // Bulan kosong di antara periode tertutup ikut ditutup agar penguncian berurutan
            if ($lockedUntil) {
                for ($m = $lockedUntil->copy()->addDay()->startOfMonth(); $m->lt($start); $m = $m->copy()->addMonthNoOverflow()) {
                    AccountingPeriod::updateOrCreate(['year' => $m->year, 'month' => $m->month], ['closed_at' => now(), 'closed_by' => $user->id]);
                }
            }

            ActivityLog::record('closed', "Menutup buku periode {$period->label()}".($notes ? ": {$notes}" : ''), $period);

            return $period;
        });
    }

    public function reopen(int $year, int $month, string $reason): AccountingPeriod
    {
        return DB::transaction(function () use ($year, $month, $reason) {
            $period = AccountingPeriod::where(['year' => $year, 'month' => $month])->lockForUpdate()->first();

            if (! $period?->isClosed()) {
                throw ValidationException::withMessages(['period' => 'Periode ini belum ditutup.']);
            }

            $later = AccountingPeriod::whereNotNull('closed_at')
                ->where(fn ($q) => $q->where('year', '>', $year)->orWhere(fn ($q) => $q->where('year', $year)->where('month', '>', $month)))
                ->exists();

            if ($later) {
                throw ValidationException::withMessages(['period' => 'Buka kembali periode tertutup terakhir terlebih dahulu.']);
            }

            $period->update(['closed_at' => null, 'closed_by' => null, 'notes' => trim(($period->notes ? $period->notes."\n" : '')."Dibuka kembali: {$reason}")]);

            ActivityLog::record('reopened', "Membuka kembali periode {$period->label()}: {$reason}", $period);

            return $period;
        });
    }
}
