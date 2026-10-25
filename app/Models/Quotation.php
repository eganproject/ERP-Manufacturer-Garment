<?php

namespace App\Models;

use App\Enums\QuotationStatus;
use App\Models\Concerns\HasActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Penawaran harga ke pelanggan (PNW-). Draft → Terkirim → Diterima (dibuatkan SO) / Ditolak,
 * atau direvisi menjadi penawaran baru. Lewat tanggal berlaku = kedaluwarsa.
 */
class Quotation extends Model
{
    use HasActivityLog;

    protected $guarded = ['id'];

    protected $casts = [
        'quotation_date' => 'date',
        'valid_until' => 'date',
        'status' => QuotationStatus::class,
        'revision' => 'integer',
        'lead_time_days' => 'integer',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'sent_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(QuotationItem::class);
    }

    public function salesOrder()
    {
        return $this->hasOne(SalesOrder::class);
    }

    public function revisedFrom()
    {
        return $this->belongsTo(self::class, 'revised_from_id');
    }

    public function revisions()
    {
        return $this->hasMany(self::class, 'revised_from_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', QuotationStatus::open());
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->open()->whereDate('valid_until', '<', today());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->open()->whereDate('valid_until', '>=', today());
    }

    public function isOpen(): bool
    {
        return in_array($this->status, QuotationStatus::open(), true);
    }

    public function isExpired(): bool
    {
        return $this->isOpen() && $this->valid_until->lt(today());
    }

    public function isEditable(): bool
    {
        return $this->status === QuotationStatus::Draft;
    }

    public function canSend(): bool
    {
        return $this->status === QuotationStatus::Draft && ! $this->isExpired();
    }

    public function canConvert(): bool
    {
        return $this->isOpen() && ! $this->isExpired();
    }

    public function canRevise(): bool
    {
        return in_array($this->status, [QuotationStatus::Sent, QuotationStatus::Rejected], true) || $this->isExpired();
    }

    /** Label & warna status untuk tampilan (termasuk kedaluwarsa). */
    public function displayStatus(): array
    {
        return $this->isExpired() ? ['Kedaluwarsa', 'amber'] : [$this->status->label(), $this->status->color()];
    }

    /** Sisa hari berlaku (negatif = lewat). */
    public function daysLeft(): int
    {
        return (int) today()->diffInDays($this->valid_until, false);
    }
}
