<?php

namespace App\Enums;

enum ProductionStage: string
{
    case Cutting = 'cutting';
    case Sewing = 'sewing';
    case Finishing = 'finishing';
    case QualityControl = 'qc';
    case Packing = 'packing';

    public function label(): string
    {
        return match ($this) {
            self::Cutting => 'Cutting (Potong)',
            self::Sewing => 'Sewing (Jahit)',
            self::Finishing => 'Finishing',
            self::QualityControl => 'Quality Control',
            self::Packing => 'Packing',
        };
    }
}
