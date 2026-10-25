<?php

namespace App\Enums;

use App\Models\Quotation;

/**
 * Status penawaran harga. "Kedaluwarsa" bukan status tersimpan: penawaran draft/terkirim
 * yang sudah lewat masa berlakunya ({@see Quotation::isExpired()}).
 */
enum QuotationStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Revised = 'revised';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Terkirim',
            self::Accepted => 'Diterima (jadi SO)',
            self::Rejected => 'Ditolak',
            self::Revised => 'Direvisi',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Sent => 'blue',
            self::Accepted => 'green',
            self::Rejected => 'red',
            self::Revised => 'indigo',
            self::Cancelled => 'red',
        };
    }

    /** Status yang masih menunggu keputusan pelanggan. */
    public static function open(): array
    {
        return [self::Draft, self::Sent];
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
