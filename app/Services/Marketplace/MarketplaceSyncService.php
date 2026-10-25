<?php

namespace App\Services\Marketplace;

use App\Enums\MarketplacePlatform;
use App\Models\ActivityLog;
use App\Models\MarketplaceStore;
use App\Services\Marketplace\Connectors\MarketplaceConnector;
use App\Services\Marketplace\Connectors\ShopeeConnector;
use App\Services\Marketplace\Connectors\TikTokConnector;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Tarik pesanan lewat API resmi (opsional, bila kredensial toko diisi). Jalur utama tetap impor file.
 */
class MarketplaceSyncService
{
    public function __construct(private MarketplaceImporter $importer) {}

    public function connector(MarketplaceStore $store): MarketplaceConnector
    {
        return match ($store->platform) {
            MarketplacePlatform::Shopee => app(ShopeeConnector::class),
            MarketplacePlatform::TikTok => app(TikTokConnector::class),
        };
    }

    /**
     * @return array{orders: int, created: int, updated: int, unchanged: int, errors: list<string>, warnings: list<string>}
     */
    public function sync(MarketplaceStore $store, ?Carbon $from = null, ?Carbon $to = null): array
    {
        if (! $store->hasApiCredentials()) {
            throw ValidationException::withMessages(['api' => "Kredensial API {$store->display_name} belum lengkap. Gunakan impor file atau lengkapi di pengaturan toko."]);
        }

        $to ??= now();
        $from ??= ($store->last_synced_at?->copy()->subHour() ?? now()->subDays(7));

        try {
            $orders = $this->connector($store)->fetchOrders($store, $from, $to);
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['api' => $e->getMessage()]);
        }

        $summary = $this->importer->importOrders($store, $orders, 'api');
        $store->update(['last_synced_at' => $to]);
        ActivityLog::record('synced', "Sinkron API {$store->display_name}: {$summary['created']} baru, {$summary['updated']} diperbarui", $store);

        return $summary;
    }
}
