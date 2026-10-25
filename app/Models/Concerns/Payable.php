<?php

namespace App\Models\Concerns;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\CreditApplication;
use App\Models\PaymentAllocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Perilaku bersama dokumen yang dapat dibayar (invoice penjualan & tagihan supplier).
 */
trait Payable
{
    public function allocations()
    {
        return $this->morphMany(PaymentAllocation::class, 'payable');
    }

    /** Nota kredit / debit retur yang dipakai untuk mengurangi dokumen ini. */
    public function creditApplications()
    {
        return $this->morphMany(CreditApplication::class, 'payable');
    }

    /** Alokasi dari pembayaran yang masih berlaku (tidak dibatalkan). */
    public function activeAllocations()
    {
        return $this->allocations()->whereHas('payment', fn ($q) => $q->where('status', PaymentStatus::Posted));
    }

    public function outstanding(): float
    {
        if ($this->status === InvoiceStatus::Cancelled) {
            return 0;
        }

        return max(0, round((float) $this->total - (float) $this->paid_amount - (float) $this->credited_amount, 2));
    }

    public function isOpen(): bool
    {
        return in_array($this->status, InvoiceStatus::open(), true);
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_date->lt(today());
    }

    public function daysOverdue(?\DateTimeInterface $asOf = null): int
    {
        $asOf = $asOf ? Carbon::parse($asOf)->startOfDay() : today();

        return max(0, (int) $this->due_date->diffInDays($asOf, false));
    }

    public function canCancel(): bool
    {
        return $this->isOpen() && (float) $this->paid_amount <= 0 && (float) $this->credited_amount <= 0;
    }

    /** Hitung ulang jumlah terbayar, nota retur terpakai, dan status dari alokasi yang berlaku. */
    public function refreshPaymentStatus(): void
    {
        if ($this->status === InvoiceStatus::Cancelled) {
            return;
        }

        $this->paid_amount = round((float) $this->activeAllocations()->sum('amount'), 2);
        $this->credited_amount = round((float) $this->creditApplications()->sum('amount'), 2);
        $settled = (float) $this->paid_amount + (float) $this->credited_amount;
        $this->status = match (true) {
            $settled <= 0 => InvoiceStatus::Unpaid,
            $settled + 0.009 >= (float) $this->total => InvoiceStatus::Paid,
            default => InvoiceStatus::Partial,
        };
        $this->save();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', InvoiceStatus::open());
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereDate('due_date', '<', today());
    }

    /** Label status untuk tampilan: "Jatuh Tempo" jika sudah lewat tempo. */
    public function displayStatus(): array
    {
        if ($this->isOverdue()) {
            return ['label' => 'Jatuh Tempo', 'color' => 'red'];
        }

        return ['label' => $this->status->label(), 'color' => $this->status->color()];
    }
}
