<?php

namespace App\Models\Concerns;

use App\Enums\ReturnStatus;
use App\Models\CreditApplication;
use App\Models\User;

/**
 * Perilaku bersama retur penjualan (nota kredit) dan retur pembelian (nota debit).
 *
 * Nilai nota (total) hanya dari barang retur yang sudah ditagih. Nota dipakai untuk mengurangi
 * invoice / tagihan (applied_amount); sisanya dapat dikembalikan sebagai uang (refunded_amount).
 */
trait ReturnNote
{
    public function applications()
    {
        return $this->morphMany(CreditApplication::class, 'source');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isPosted(): bool
    {
        return $this->status === ReturnStatus::Posted;
    }

    /** Sisa nota yang belum dipakai maupun dikembalikan. */
    public function balance(): float
    {
        if (! $this->isPosted()) {
            return 0;
        }

        return max(0, round((float) $this->total - (float) $this->applied_amount - (float) $this->refunded_amount, 2));
    }

    public function hasBalance(): bool
    {
        return $this->balance() > 0.009;
    }

    /** Retur yang sudah ada pengembalian dana harus dibatalkan pengembaliannya lebih dulu (tidak didukung). */
    public function canCancel(): bool
    {
        return $this->isPosted() && (float) $this->refunded_amount <= 0;
    }

    public function refreshAppliedAmount(): void
    {
        $this->applied_amount = round((float) $this->applications()->sum('amount'), 2);
        $this->save();
    }

    /** HPP barang retur yang sudah ditagih (koreksi HPP penjualan). */
    public function billedCost(): float
    {
        return round((float) $this->items->sum('billed_cost'), 2);
    }

    /** Label status untuk tampilan. */
    public function displayStatus(): array
    {
        return match (true) {
            ! $this->isPosted() => ['label' => $this->status->label(), 'color' => $this->status->color()],
            $this->hasBalance() => ['label' => 'Sisa Nota', 'color' => 'amber'],
            (float) $this->total > 0 => ['label' => 'Nota Terpakai', 'color' => 'green'],
            default => ['label' => 'Diposting', 'color' => 'green'],
        };
    }
}
