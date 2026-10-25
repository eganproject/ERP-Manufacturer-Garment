<?php

namespace App\Services\Marketplace\Connectors;

use App\Models\MarketplaceStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * TikTok Shop Partner API (versi 202309). Butuh app_key, app_secret, access token & shop_cipher toko.
 * Tanda tangan: HMAC-SHA256(secret, secret + path + parameter terurut (key+value) + body + secret).
 */
class TikTokConnector implements MarketplaceConnector
{
    public const BASE_URL = 'https://open-api.tiktokglobalshop.com';

    public const STATUSES = [
        'UNPAID' => 'Belum Dibayar',
        'ON_HOLD' => 'Akan Dikirim',
        'AWAITING_SHIPMENT' => 'Akan Dikirim',
        'PARTIALLY_SHIPPING' => 'Akan Dikirim',
        'AWAITING_COLLECTION' => 'Akan Dikirim',
        'IN_TRANSIT' => 'Dikirim',
        'DELIVERED' => 'Selesai',
        'COMPLETED' => 'Selesai',
        'CANCELLED' => 'Dibatalkan',
    ];

    public function fetchOrders(MarketplaceStore $store, Carbon $from, Carbon $to): array
    {
        $orders = [];
        $pageToken = null;

        do {
            $query = ['page_size' => 50] + ($pageToken ? ['page_token' => $pageToken] : []);
            $data = $this->post($store, '/order/202309/orders/search', $query, [
                'update_time_ge' => $from->timestamp,
                'update_time_lt' => $to->timestamp,
            ]);

            foreach ($data['data']['orders'] ?? [] as $row) {
                $orders[] = $this->normalize($row);
            }
            $pageToken = $data['data']['next_page_token'] ?? null;
        } while ($pageToken);

        return $orders;
    }

    /** @param  array<string, mixed>  $row */
    public function normalize(array $row): array
    {
        $time = fn ($v) => $v ? Carbon::createFromTimestamp((int) $v, config('app.timezone')) : null;
        $status = $row['status'] ?? '';

        // Pada API 202309 satu line item = satu unit; digabung per SKU
        $items = collect($row['line_items'] ?? [])->groupBy(fn ($i) => ($i['seller_sku'] ?? '').'|'.($i['sku_name'] ?? ''))->map(fn ($group) => [
            'sku' => $group[0]['seller_sku'] ?? null,
            'name' => $group[0]['product_name'] ?? null,
            'variation' => $group[0]['sku_name'] ?? null,
            'quantity' => (float) $group->count(),
            'unit_price' => (float) ($group[0]['sale_price'] ?? 0),
            'subtotal' => round($group->sum(fn ($i) => (float) ($i['sale_price'] ?? 0)), 2),
        ])->values()->all();

        return [
            'order_number' => (string) $row['id'],
            'status' => self::STATUSES[$status] ?? $status,
            'order_date' => $time($row['create_time'] ?? null) ?? now(),
            'paid_at' => $time($row['paid_time'] ?? null),
            'shipped_at' => $time($row['collection_time'] ?? $row['shipping_time'] ?? null),
            'completed_at' => $time($row['delivery_time'] ?? null),
            'cancel_reason' => $row['cancel_reason'] ?? null,
            'buyer' => $row['buyer_email'] ?? null,
            'recipient' => $row['recipient_address']['name'] ?? null,
            'city' => collect($row['recipient_address']['district_info'] ?? [])->firstWhere('address_level_name', 'City')['address_name'] ?? null,
            'courier' => $row['shipping_provider'] ?? null,
            'tracking_number' => $row['tracking_number'] ?? null,
            'shipping_fee' => (float) ($row['payment']['shipping_fee'] ?? 0),
            'items' => $items,
        ];
    }

    /** @param  array<string, scalar>  $query */
    public static function sign(string $secret, string $path, array $query, string $body = ''): string
    {
        unset($query['sign'], $query['access_token']);
        ksort($query);
        $params = collect($query)->map(fn ($v, $k) => $k.$v)->implode('');

        return hash_hmac('sha256', $secret.$path.$params.$body.$secret, $secret);
    }

    /** @return array<string, mixed> */
    private function post(MarketplaceStore $store, string $path, array $query, array $body): array
    {
        $query += ['app_key' => $store->api_partner_id, 'timestamp' => now()->timestamp, 'shop_cipher' => $store->api_shop_cipher];
        $json = json_encode($body);
        $query['sign'] = self::sign((string) $store->api_partner_key, $path, $query, $json);

        $response = Http::baseUrl(self::BASE_URL)->timeout(30)
            ->withHeaders(['x-tts-access-token' => $store->api_access_token, 'content-type' => 'application/json'])
            ->withBody($json, 'application/json')
            ->post($path.'?'.http_build_query($query));

        $data = $response->json() ?? [];
        if ($response->failed() || (int) ($data['code'] ?? 0) !== 0) {
            throw new RuntimeException('TikTok Shop API: '.($data['message'] ?? $response->status()));
        }

        return $data;
    }
}
