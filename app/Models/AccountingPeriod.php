<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Periode akuntansi bulanan. Periode yang ditutup tidak menerima jurnal baru (termasuk jurnal otomatis).
 */
class AccountingPeriod extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'closed_at' => 'datetime',
    ];

    public function closer()
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    public function start(): Carbon
    {
        return Carbon::create($this->year, $this->month, 1)->startOfDay();
    }

    public function end(): Carbon
    {
        return $this->start()->endOfMonth()->startOfDay();
    }

    public function label(): string
    {
        return $this->start()->translatedFormat('F Y');
    }

    public static function for(\DateTimeInterface $date): self
    {
        $date = Carbon::parse($date);

        return static::firstOrNew(['year' => $date->year, 'month' => $date->month]);
    }

    /** Tanggal terakhir yang sudah dikunci (akhir bulan terakhir yang ditutup), null bila belum ada. */
    public static function lockedUntil(): ?Carbon
    {
        $last = static::whereNotNull('closed_at')->orderByDesc('year')->orderByDesc('month')->first();

        return $last?->end();
    }

    public static function isLocked(\DateTimeInterface $date): bool
    {
        $until = static::lockedUntil();

        return $until !== null && Carbon::parse($date)->startOfDay()->lte($until);
    }
}
