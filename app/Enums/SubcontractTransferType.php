<?php

namespace App\Enums;

/**
 * Arah perpindahan bahan maklon: dikirim ke vendor atau sisa bahan diretur ke gudang.
 */
enum SubcontractTransferType: string
{
    case Dispatch = 'dispatch';
    case Return = 'return';

    public function label(): string
    {
        return match ($this) {
            self::Dispatch => 'Kirim Bahan',
            self::Return => 'Retur Sisa Bahan',
        };
    }

    public function documentTitle(): string
    {
        return match ($this) {
            self::Dispatch => 'SURAT JALAN BAHAN MAKLON',
            self::Return => 'BUKTI RETUR BAHAN MAKLON',
        };
    }

    public function prefix(): string
    {
        return match ($this) {
            self::Dispatch => 'KBM',
            self::Return => 'RBM',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Dispatch => 'indigo',
            self::Return => 'amber',
        };
    }
}
