<?php

namespace App\Services\Reports;

use App\Enums\MarketplaceSettlementStatus;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceOrderItem;
use App\Support\ReportTable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Penjualan & margin TikTok Shop / Shopee per platform / toko, per produk, atau per pesanan.
 * Basis: pesanan yang dikirim pada periode (penjualan diakui saat kirim), bersih retur.
 * Margin = penjualan bersih (DPP) − voucher penjual − biaya platform − ongkir ditanggung penjual
 * − selisih pencairan − HPP (termasuk barang retur yang hilang / tertukar, dikurangi kompensasi banding). Potongan platform berasal dari pencairan dana yang sudah diposting.
 */
class MarketplaceReport
{
    public const VIEWS = ['stores' => 'Per platform & toko', 'products' => 'Per produk', 'orders' => 'Per pesanan'];

    /**
     * @return array{rows: Collection, totals: array<string, float|int>}
     */
    public function build(Carbon $from, Carbon $to, string $view = 'stores', ?string $platform = null, ?int $storeId = null): array
    {
        $orders = MarketplaceOrder::with(['store', 'settlementLines' => fn ($q) => $q->whereHas('settlement', fn ($s) => $s->where('status', MarketplaceSettlementStatus::Posted))])
            ->whereBetween('shipped_at', [$from->toDateString(), $to->format('Y-m-d 23:59:59')])
            ->when($storeId, fn ($q) => $q->where('marketplace_store_id', $storeId))
            ->when($platform, fn ($q) => $q->whereHas('store', fn ($s) => $s->where('platform', $platform)))
            ->orderBy('shipped_at')->orderBy('id')
            ->get();

        $metrics = $orders->map(fn (MarketplaceOrder $o) => $this->orderMetrics($o));

        $rows = match ($view) {
            'orders' => $metrics->values(),
            'products' => $this->products($orders),
            default => $metrics->groupBy('store_id')->map(fn (Collection $group) => $this->sum($group) + [
                'platform' => $group->first()['platform'],
                'store' => $group->first()['store'],
                'store_id' => $group->first()['store_id'],
            ])->sortBy(['platform', 'store'])->values(),
        };

        $totals = $this->sum($metrics);
        $totals['unsettled'] = $orders->filter(fn ($o) => $o->outstandingReceivable() > 0)->count();
        $totals['outstanding'] = round($orders->sum(fn ($o) => $o->outstandingReceivable()), 2);

        if ($view === 'products') {
            $totals = ['qty' => round($rows->sum('qty'), 2), 'sales' => round($rows->sum('sales'), 2), 'cost' => round($rows->sum('cost'), 2)];
            $totals['gross_profit'] = round($totals['sales'] - $totals['cost'], 2);
            $totals['margin_pct'] = $totals['sales'] > 0 ? round($totals['gross_profit'] / $totals['sales'] * 100, 1) : 0;
        }

        return ['rows' => $rows, 'totals' => $totals];
    }

    /** @return array<string, mixed> */
    private function orderMetrics(MarketplaceOrder $o): array
    {
        $lines = $o->settlementLines;
        $voucher = round((float) $lines->sum('voucher'), 2);
        $fees = round((float) $lines->sum(fn ($l) => $l->fees()), 2);
        $shipping = round((float) $lines->sum('shipping_fee'), 2);
        // Bagian piutang yang tidak tertutup dana + potongan yang terbaca = selisih / penyesuaian
        $adjustment = round((float) $lines->sum(fn ($l) => (float) $l->receivable - (float) $l->net_amount - (float) $l->voucher - $l->fees() - (float) $l->shipping_fee), 2);
        $sales = $o->netSales();
        // HPP bersih: barang yang kembali dikeluarkan; barang yang hilang / tertukar tetap beban, dikurangi kompensasi banding
        $cost = round($o->netCost() - $o->claimCompensation(), 2);
        $margin = round($sales - $voucher - $fees - $shipping - $adjustment - $cost, 2);

        return [
            'store_id' => $o->marketplace_store_id,
            'platform' => $o->store->platform->label(),
            'store' => $o->store->name,
            'order_id' => $o->id,
            'order_number' => $o->order_number,
            'date' => $o->shipped_at,
            'status' => $o->status->label(),
            'orders' => 1,
            'gross' => (float) $o->dpp,
            'returns' => (float) $o->returned_dpp,
            'sales' => $sales,
            'voucher' => $voucher,
            'fees' => $fees,
            'shipping' => $shipping,
            'adjustment' => $adjustment,
            'cost' => $cost,
            'margin' => $margin,
            'margin_pct' => $sales > 0 ? round($margin / $sales * 100, 1) : 0,
            'net_received' => (float) $o->settled_net,
            'settled' => $lines->isNotEmpty(),
        ];
    }

    /** @return array<string, float|int> */
    private function sum(Collection $rows): array
    {
        $t = [];
        foreach (['orders', 'gross', 'returns', 'sales', 'voucher', 'fees', 'shipping', 'adjustment', 'cost', 'margin', 'net_received'] as $key) {
            $t[$key] = round((float) $rows->sum($key), 2);
        }
        $t['margin_pct'] = $t['sales'] > 0 ? round($t['margin'] / $t['sales'] * 100, 1) : 0;
        $t['fee_pct'] = $t['sales'] > 0 ? round(($t['voucher'] + $t['fees'] + $t['shipping']) / $t['sales'] * 100, 1) : 0;

        return $t;
    }

    private function products(Collection $orders): Collection
    {
        return MarketplaceOrderItem::with('variant.product')
            ->whereIn('marketplace_order_id', $orders->pluck('id'))
            ->get()
            ->groupBy('product_variant_id')
            ->map(function (Collection $items) {
                $variant = $items->first()->variant;
                $sales = $items->sum(fn ($i) => (float) $i->dpp * (1 - ((float) $i->quantity > 0 ? (float) $i->returned_quantity / (float) $i->quantity : 0)));
                $cost = $items->sum(fn ($i) => (float) $i->cost - (float) $i->restocked_quantity * (float) $i->units_per_item * (float) $i->unit_cost);
                $sales = round($sales, 2);
                $cost = round($cost, 2);

                return [
                    'sku' => $variant?->sku ?? '-',
                    'product' => $variant?->display_name ?? $items->first()->label(),
                    'qty' => round($items->sum(fn ($i) => ((float) $i->quantity - (float) $i->returned_quantity) * (float) $i->units_per_item), 2),
                    'sales' => $sales,
                    'cost' => $cost,
                    'gross_profit' => round($sales - $cost, 2),
                    'margin_pct' => $sales > 0 ? round(($sales - $cost) / $sales * 100, 1) : 0,
                ];
            })
            ->sortByDesc('sales')
            ->values();
    }

    public function table(array $data, string $view, Carbon $from, Carbon $to): ReportTable
    {
        $money = fn ($label) => [$label, 'money'];

        $columns = match ($view) {
            'products' => ['sku' => ['SKU', 'text'], 'product' => ['Produk', 'text'], 'qty' => ['Qty Bersih (pcs)', 'qty'], 'sales' => $money('Penjualan (DPP)'),
                'cost' => $money('HPP'), 'gross_profit' => $money('Laba Kotor'), 'margin_pct' => ['Margin', 'percent']],
            default => ($view === 'orders'
                ? ['date' => ['Tgl Kirim', 'date'], 'order_number' => ['No. Pesanan', 'text'], 'platform' => ['Platform', 'text'], 'store' => ['Toko', 'text'], 'status' => ['Status', 'text']]
                : ['platform' => ['Platform', 'text'], 'store' => ['Toko', 'text'], 'orders' => ['Pesanan', 'number']])
                + ['gross' => $money('Penjualan (DPP)'), 'returns' => $money('Retur'), 'sales' => $money('Penjualan Bersih'), 'voucher' => $money('Voucher Penjual'),
                    'fees' => $money('Biaya Platform'), 'shipping' => $money('Ongkir Penjual'), 'adjustment' => $money('Selisih Pencairan'), 'cost' => $money('HPP'),
                    'margin' => $money('Margin'), 'margin_pct' => ['Margin %', 'percent'], 'net_received' => $money('Dana Cair')],
        };

        $t = $data['totals'];

        return new ReportTable(
            title: 'Laporan Penjualan Marketplace',
            subtitle: self::VIEWS[$view].' · '.tanggal($from).' s.d. '.tanggal($to),
            columns: $columns,
            rows: $data['rows'],
            totals: array_intersect_key($t, $columns),
            summary: $view === 'products' ? [['Penjualan bersih', rupiah($t['sales'])], ['Laba kotor', rupiah($t['gross_profit'])]] : [
                ['Penjualan bersih', rupiah($t['sales'])],
                ['Potongan platform (voucher + biaya + ongkir)', rupiah($t['voucher'] + $t['fees'] + $t['shipping']).' ('.$t['fee_pct'].'%)'],
                ['Margin', rupiah($t['margin']).' ('.$t['margin_pct'].'%)'],
                ['Pesanan belum cair', $t['unsettled'].' pesanan · piutang '.rupiah($t['outstanding'])],
            ],
            orientation: 'landscape',
        );
    }
}
