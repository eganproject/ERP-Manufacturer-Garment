<?php

namespace App\Enums;

enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Ordered = 'ordered';
    case Partial = 'partial';
    case Received = 'received';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::PendingApproval => 'Menunggu Persetujuan',
            self::Ordered => 'Dipesan',
            self::Partial => 'Diterima Sebagian',
            self::Received => 'Diterima Lengkap',
            self::Cancelled => 'Dibatalkan',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::PendingApproval => 'indigo',
            self::Ordered => 'blue',
            self::Partial => 'amber',
            self::Received => 'green',
            self::Cancelled => 'red',
        };
    }
}
