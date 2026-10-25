<?php

namespace App\Services\Marketplace\Connectors;

use App\Models\MarketplaceStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Shopee Open Platform v2 (https://open.shopee.com). Butuh partner_id, partner_key, shop_id & access token
 * hasil otorisasi toko. Tanda tangan: HMAC-SHA256(partner_key, partner_id + path + timestamp + access_token + shop_id).
 */
class ShopeeConnector implements MarketplaceConnector
{
    public const BASE_URL = 'https://partner.shopeemobile.com';

    /** Status API → istilah status Seller Center (dikenali MarketplaceOrderStatus::fromPlatform). */
    public const STATUSES = [
        'UNPAID' => 'Belum Bayar',
        'READY_TO_SHIP' => 'Perlu Dikirim',
        'PROCESSED' => 'Perlu Dikirim',
        'RETRY_SHIP' => 'Perlu Dikirim',
        'SHIPPED' => 'Dikirim',
        'TO_CONFIRM_RECEIVE' => 'Dikirim',
        'INVOICE_PENDING' => 'Dikirim',
        'COMPLETED' => 'Selesai',
        'IN_CANCEL' => 'Batal',
        'CANCELLED' => 'Batal',
        'TO_RETURN' => 'Pengembalian Barang',
    ];

    public function fetchOrders(MarketplaceStore $store, Carbon $from, Carbon $to): array
    {
        $numbers = [];
        // Rentang update_time maksimal 15 hari per permintaan
        for ($start = $from->copy(); $start->lt($to); $start->addDays(15)) {
            $end = $start->copy()->addDays(15)->min($to);
            $cursor = '';
            do {
                $data = $this->get($store, '/api/v2/order/get_order_list', [
                    'time_range_field' => 'update_time', 'time_from' => $start->timestamp, 'time_to' => $end->timestamp,
                    'page_size' => 100, 'cursor' => $cursor,
                ]);
                foreach ($data['response']['order_list'] ?? [] as $row) {
                    $numbers[] = $row['order_sn'];
                }
                $cursor = $data['response']['next_cursor'] ?? '';
            } while (! empty($data['response']['more']));
        }

        $orders = [];
        foreach (array_chunk(array_unique($numbers), 50) as $chunk) {
            $data = $this->get($store, '/api/v2/order/get_order_detail', [
                'order_sn_list' => implode(',', $chunk),
                'response_optional_fields' => 'buyer_username,recipient_address,item_list,pay_time,shipping_carrier,actual_shipping_fee,cancel_reason,pickup_done_time',
            ]);
            foreach ($data['response']['order_list'] ?? [] as $row) {
                $orders[] = $this->normalize($row);
            }
        }

        return $orders;
    }

    /** @param  array<string, mixed>  $row */
    public function normalize(array $row): array
    {
        $time = fn ($v) => $v ? Carbon::createFromTimestamp((int) $v, config('app.timezone')) : null;

        return [
            'order_number' => (string) $row['order_sn'],
            'status' => self::STATUSES[$row['order_status'] ?? ''] ?? ($row['order_status'] ?? ''),
            'order_date' => $time($row['create_time'] ?? null) ?? now(),
            'paid_at' => $time($row['pay_time'] ?? null),
            'shipped_at' => $time($row['pickup_done_time'] ?? null),
            'completed_at' => ($row['order_status'] ?? '') === 'COMPLETED' ? $time($row['update_time'] ?? null) : null,
            'cancel_reason' => $row['cancel_reason'] ?? null,
            'buyer' => $row['buyer_username'] ?? null,
            'recipient' => $row['recipient_address']['name'] ?? null,
            'city' => $row['recipient_address']['city'] ?? null,
            'courier' => $row['shipping_carrier'] ?? null,
            'tracking_number' => null,
            'shipping_fee' => 0,
            'items' => collect($row['item_list'] ?? [])->map(fn ($i) => [
                'sku' => ($i['model_sku'] ?? '') ?: ($i['item_sku'] ?? null),
                'name' => $i['item_name'] ?? null,
                'variation' => $i['model_name'] ?? null,
                'quantity' => (float) ($i['model_quantity_purchased'] ?? 1),
                'unit_price' => (float) ($i['model_discounted_price'] ?? 0),
                'subtotal' => round((float) ($i['model_discounted_price'] ?? 0) * (float) ($i['model_quantity_purchased'] ?? 1), 2),
            ])->all(),
        ];
    }

    public static function sign(string $partnerKey, string $partnerId, string $path, int $timestamp, string $accessToken, string $shopId): string
    {
        return hash_hmac('sha256', $partnerId.$path.$timestamp.$accessToken.$shopId, $partnerKey);
    }

    /** @return array<string, mixed> */
    private function get(MarketplaceStore $store, string $path, array $query): array
    {
        $timestamp = now()->timestamp;
        $response = Http::baseUrl(self::BASE_URL)->timeout(30)->get($path, $query + [
            'partner_id' => (int) $store->api_partner_id,
            'shop_id' => (int) $store->shop_id,
            'timestamp' => $timestamp,
            'access_token' => $store->api_access_token,
            'sign' => self::sign((string) $store->api_partner_key, (string) $store->api_partner_id, $path, $timestamp, (string) $store->api_access_token, (string) $store->shop_id),
        ]);

        $data = $response->json() ?? [];
        if ($response->failed() || ! empty($data['error'])) {
            throw new RuntimeException('Shopee API: '.($data['message'] ?? $data['error'] ?? $response->status()));
        }

        return $data;
    }
}
