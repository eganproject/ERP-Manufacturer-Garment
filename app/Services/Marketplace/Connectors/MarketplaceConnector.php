<?php

namespace App\Services\Marketplace\Connectors;

use App\Models\MarketplaceStore;
use App\Services\Marketplace\MarketplaceImporter;
use Illuminate\Support\Carbon;

/**
 * Integrasi API resmi marketplace (opsional). Hasil pesanan memakai format ternormalisasi yang sama
 * dengan impor file ({@see MarketplaceImporter}), sehingga alur stok & jurnal identik.
 */
interface MarketplaceConnector
{
    /**
     * Pesanan yang berubah pada rentang waktu tsb.
     *
     * @return list<array<string, mixed>>
     */
    public function fetchOrders(MarketplaceStore $store, Carbon $from, Carbon $to): array;
}
