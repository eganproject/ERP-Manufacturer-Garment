<?php

namespace App\Enums;

enum PaymentType: string
{
    /** Penerimaan dari pelanggan (pelunasan piutang). */
    case Receipt = 'receipt';

    /** Pembayaran ke supplier (pelunasan hutang). */
    case Disbursement = 'disbursement';

    public function label(): string
    {
        return match ($this) {
            self::Receipt => 'Penerimaan Pelanggan',
            self::Disbursement => 'Pembayaran Supplier',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Receipt => 'green',
            self::Disbursement => 'indigo',
        };
    }

    public function prefix(): string
    {
        return match ($this) {
            self::Receipt => 'RV',
            self::Disbursement => 'PV',
        };
    }

    /** Hak akses untuk mencatat jenis pembayaran ini. */
    public function manageAbility(): string
    {
        return match ($this) {
            self::Receipt => 'receivables.manage',
            self::Disbursement => 'payables.manage',
        };
    }

    public function viewAbility(): string
    {
        return match ($this) {
            self::Receipt => 'receivables.view',
            self::Disbursement => 'payables.view',
        };
    }

    public function partyLabel(): string
    {
        return match ($this) {
            self::Receipt => 'Pelanggan',
            self::Disbursement => 'Supplier',
        };
    }
}
