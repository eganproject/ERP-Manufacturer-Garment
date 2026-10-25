<?php

namespace App\Support;

/**
 * Label dan nama route untuk tampilan retur penjualan / pembelian yang memakai view yang sama.
 */
final class ReturnKind
{
    private function __construct(
        public bool $isSales,
        public string $title,
        public string $route,
        public string $prefix,
        public string $partyLabel,
        public string $partyRelation,
        public string $orderLabel,
        public string $orderRelation,
        public string $orderRoute,
        public string $orderCan,
        public string $itemLabel,
        public string $shippedLabel,
        public string $documentLabel,
        public string $documentRoute,
        public string $noteLabel,
        public string $manageCan,
        public string $financeCan,
        public string $refundLabel,
    ) {}

    public static function sales(): self
    {
        return new self(
            isSales: true,
            title: 'Retur Penjualan',
            route: 'sales-returns',
            prefix: 'RJ',
            partyLabel: 'Pelanggan',
            partyRelation: 'customer',
            orderLabel: 'Sales Order',
            orderRelation: 'salesOrder',
            orderRoute: 'sales-orders.show',
            orderCan: 'sales.view',
            itemLabel: 'Produk',
            shippedLabel: 'Terkirim',
            documentLabel: 'Invoice',
            documentRoute: 'invoices.show',
            noteLabel: 'Nota Kredit',
            manageCan: 'sales-returns.manage',
            financeCan: 'receivables.manage',
            refundLabel: 'Kembalikan dana ke pelanggan',
        );
    }

    public static function purchases(): self
    {
        return new self(
            isSales: false,
            title: 'Retur Pembelian',
            route: 'purchase-returns',
            prefix: 'RB',
            partyLabel: 'Supplier',
            partyRelation: 'supplier',
            orderLabel: 'Purchase Order',
            orderRelation: 'purchaseOrder',
            orderRoute: 'purchase-orders.show',
            orderCan: 'purchase.view',
            itemLabel: 'Bahan',
            shippedLabel: 'Diterima',
            documentLabel: 'Tagihan',
            documentRoute: 'bills.show',
            noteLabel: 'Nota Debit',
            manageCan: 'purchase-returns.manage',
            financeCan: 'payables.manage',
            refundLabel: 'Terima pengembalian dana dari supplier',
        );
    }
}
