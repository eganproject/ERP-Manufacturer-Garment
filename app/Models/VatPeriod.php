<?php

namespace App\Models;

use App\Enums\ReturnStatus;
use App\Models\Concerns\HasActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Penutupan masa PPN (PPN-) satu bulan: PPN keluaran − PPN masukan − kompensasi masa lalu.
 * Positif = kurang bayar (Hutang PPN, disetor lewat kas keluar), negatif = lebih bayar
 * yang dikompensasikan ke masa berikutnya.
 */
class VatPeriod extends Model
{
    use HasActivityLog;

    protected $guarded = ['id'];

    protected $casts = [
        'period' => 'date',
        'date' => 'date',
        'status' => ReturnStatus::class,
        'output_tax' => 'decimal:2',
        'input_tax' => 'decimal:2',
        'compensation' => 'decimal:2',
        'net' => 'decimal:2',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePosted(Builder $query): Builder
    {
        return $query->where('status', ReturnStatus::Posted);
    }

    public function isCancelled(): bool
    {
        return $this->status === ReturnStatus::Cancelled;
    }

    public function periodLabel(): string
    {
        return $this->period->translatedFormat('F Y');
    }

    /** Kurang bayar yang harus disetor (0 bila lebih bayar). */
    public function payable(): float
    {
        return max(0, (float) $this->net);
    }

    /** Lebih bayar yang dibawa ke masa berikutnya (0 bila kurang bayar). */
    public function overpaid(): float
    {
        return max(0, -(float) $this->net);
    }
}
