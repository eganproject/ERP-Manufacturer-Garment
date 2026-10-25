<?php

namespace App\Enums;

enum StockMovementType: string
{
    case PurchaseReceipt = 'purchase_receipt';
    case ProductionIssue = 'production_issue';
    case ProductionOutput = 'production_output';
    case SalesDelivery = 'sales_delivery';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::PurchaseReceipt => 'Penerimaan Pembelian',
            self::ProductionIssue => 'Pemakaian Produksi',
            self::ProductionOutput => 'Hasil Produksi',
            self::SalesDelivery => 'Pengiriman Penjualan',
            self::Adjustment => 'Penyesuaian Stok',
        };
    }
}
