<?php

namespace App\Services\Reports;

use App\Enums\InvoiceStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\ReturnStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Bill;
use App\Models\Invoice;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Support\ReportTable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Laporan penjualan (dari invoice) dan pembelian (dari tagihan supplier) per periode,
 * dikelompokkan per pelanggan/supplier, per produk/bahan, atau per bulan.
 *
 * Nilai penjualan/pembelian memakai DPP (subtotal dikurangi diskon, sebelum PPN).
 * Diskon header dibagi proporsional ke tiap baris saat dikelompokkan per produk/bahan.
 *
 * Penjualan dengan $withCost juga menghitung HPP (dari invoice_items.cost), laba kotor, dan margin.
 *
 * Nota retur (bagian retur yang sudah ditagih) pada periode yang sama ikut dihitung sebagai
 * pengurang bernilai negatif: DPP, PPN, total, qty, dan HPP (koreksi HPP penjualan).
 */
class TradeReport
{
    public const GROUPS = [
        'party' => 'Per Pelanggan / Supplier',
        'item' => 'Per Produk / Bahan',
        'month' => 'Per Bulan',
    ];

    /**
     * @param  'sales'|'purchases'  $kind
     * @param  'party'|'item'|'month'  $groupBy
     * @return array{rows: Collection, totals: array<string, float>, summary: array<string, float>, documents: Collection}
     */
    public function build(string $kind, Carbon $from, Carbon $to, string $groupBy = 'party', ?int $partyId = null, bool $withCost = false): array
    {
        $isSales = $kind === 'sales';
        $withCost = $withCost && $isSales;
        [$model, $dateColumn, $partyColumn, $partyRelation, $itemRelation] = $isSales
            ? [Invoice::class, 'invoice_date', 'customer_id', 'customer', 'items.variant.product.unit']
            : [Bill::class, 'bill_date', 'supplier_id', 'supplier', 'items.material.unit'];

        $documents = $model::query()
            ->with([$partyRelation, $itemRelation])
            ->where('status', '!=', InvoiceStatus::Cancelled)
            ->whereBetween($dateColumn, [$from->toDateString(), $to->toDateString()])
            ->when($partyId, fn ($q, $id) => $q->where($partyColumn, $id))
            ->orderBy($dateColumn)
            ->orderBy('id')
            ->get()
            ->each(function ($doc) use ($withCost) {
                $doc->is_return = false;
                $doc->dpp = round((float) $doc->subtotal - (float) $doc->discount, 2);
                $doc->cost = $withCost ? round((float) $doc->items->sum('cost'), 2) : 0.0;
                $doc->outstanding_amount = $doc->outstanding();
                $doc->quantity = (float) $doc->items->sum('quantity');
            });

        $returns = $this->returnDocuments($isSales, $from, $to, $partyId, $withCost, $dateColumn);
        $documents = $documents->concat($returns)->values();

        $rows = match ($groupBy) {
            'item' => $this->byItem($documents, $isSales),
            'month' => $this->byMonth($documents, $dateColumn),
            default => $this->byParty($documents, $partyColumn, $partyRelation),
        };

        $totals = [
            'count' => self::countInvoices($documents),
            'returns' => round(-1 * $returns->sum('dpp'), 2),
            'quantity' => round($documents->sum('quantity'), 2),
            'dpp' => round($documents->sum('dpp'), 2),
            'tax' => round($documents->sum(fn ($d) => (float) $d->tax_amount), 2),
            'total' => round($documents->sum(fn ($d) => (float) $d->total), 2),
            'paid' => round($documents->sum(fn ($d) => (float) $d->paid_amount), 2),
            'outstanding' => round($documents->sum('outstanding_amount'), 2),
            'cost' => round($documents->sum('cost'), 2),
        ];
        $totals = self::withProfit($totals);

        // Pembanding: nilai pesanan (SO/PO) yang dibuat pada periode yang sama
        $orders = $isSales
            ? SalesOrder::whereNotIn('status', [SalesOrderStatus::Draft, SalesOrderStatus::Cancelled])
                ->when($partyId, fn ($q, $id) => $q->where('customer_id', $id))
            : PurchaseOrder::whereNotIn('status', [PurchaseOrderStatus::Draft, PurchaseOrderStatus::PendingApproval, PurchaseOrderStatus::Cancelled])
                ->when($partyId, fn ($q, $id) => $q->where('supplier_id', $id));

        $summary = [
            'orders' => (float) $orders->whereBetween('order_date', [$from->toDateString(), $to->toDateString()])->sum('total'),
            'average' => $totals['count'] > 0 ? round(($totals['dpp'] + $totals['returns']) / $totals['count'], 2) : 0,
        ];

        return compact('rows', 'totals', 'summary', 'documents');
    }

    /**
     * Nota retur pada periode, dibentuk seperti invoice/tagihan bernilai negatif agar bisa
     * dikelompokkan bersama. Retur tanpa nilai nota (barang belum ditagih) tidak memengaruhi penjualan.
     */
    private function returnDocuments(bool $isSales, Carbon $from, Carbon $to, ?int $partyId, bool $withCost, string $dateColumn): Collection
    {
        [$model, $partyColumn, $relations] = $isSales
            ? [SalesReturn::class, 'customer_id', ['customer', 'items.variant.product.unit']]
            : [PurchaseReturn::class, 'supplier_id', ['supplier', 'items.material.unit']];

        return $model::query()
            ->with($relations)
            ->where('status', ReturnStatus::Posted)
            ->where('total', '>', 0)
            ->whereBetween('return_date', [$from->toDateString(), $to->toDateString()])
            ->when($partyId, fn ($q, $id) => $q->where($partyColumn, $id))
            ->orderBy('return_date')
            ->get()
            ->map(function ($return) use ($withCost, $dateColumn) {
                $items = $return->items->filter(fn ($i) => (float) $i->billed_quantity > 0)->map(function ($item) {
                    $line = clone $item;
                    $line->quantity = -1 * (float) $item->billed_quantity;
                    $line->subtotal = -1 * (float) $item->amount;
                    $line->cost = -1 * (float) $item->billed_cost;

                    return $line;
                })->values();

                $doc = clone $return;
                $doc->setRelation('items', $items);
                $doc->is_return = true;
                $doc->{$dateColumn} = $return->return_date;
                $doc->subtotal = -1 * (float) $return->amount;
                $doc->discount = 0;
                $doc->dpp = -1 * (float) $return->amount;
                $doc->tax_amount = -1 * (float) $return->tax_amount;
                $doc->total = -1 * (float) $return->total;
                $doc->paid_amount = 0;
                $doc->outstanding_amount = 0.0;
                $doc->cost = $withCost ? -1 * $return->billedCost() : 0.0;
                $doc->quantity = (float) $items->sum('quantity');

                return $doc;
            });
    }

    private static function countInvoices(Collection $documents): int
    {
        return $documents->reject(fn ($d) => $d->is_return)->count();
    }

    private function byParty(Collection $documents, string $partyColumn, string $partyRelation): Collection
    {
        return $documents->groupBy($partyColumn)->map(fn (Collection $docs) => [
            'key' => $docs->first()->{$partyColumn},
            'code' => $docs->first()->{$partyRelation}->code,
            'name' => $docs->first()->{$partyRelation}->name,
            'count' => self::countInvoices($docs),
            'quantity' => round($docs->sum('quantity'), 2),
            'dpp' => round($docs->sum('dpp'), 2),
            'tax' => round($docs->sum(fn ($d) => (float) $d->tax_amount), 2),
            'total' => round($docs->sum(fn ($d) => (float) $d->total), 2),
            'paid' => round($docs->sum(fn ($d) => (float) $d->paid_amount), 2),
            'outstanding' => round($docs->sum('outstanding_amount'), 2),
            'cost' => round($docs->sum('cost'), 2),
        ])->map(fn ($row) => self::withProfit($row))->sortByDesc('dpp')->values()->pipe(fn ($rows) => $this->withShare($rows));
    }

    private function byItem(Collection $documents, bool $isSales): Collection
    {
        $lines = $documents->flatMap(function ($doc) use ($isSales) {
            $factor = (float) $doc->subtotal > 0 ? $doc->dpp / (float) $doc->subtotal : 1;

            return $doc->items->map(function ($item) use ($factor, $isSales, $doc) {
                $owner = $isSales ? $item->variant->product : $item->material;

                return [
                    'owner' => $owner,
                    'doc' => $doc->id,
                    'is_return' => $doc->is_return,
                    'quantity' => (float) $item->quantity,
                    'dpp' => (float) $item->subtotal * $factor,
                    'cost' => $isSales ? (float) $item->cost : 0.0,
                ];
            });
        });

        return $lines->groupBy(fn ($line) => $line['owner']->id)->map(function (Collection $lines) {
            $owner = $lines->first()['owner'];
            $qty = $lines->sum('quantity');
            $dpp = $lines->sum('dpp');

            return [
                'key' => $owner->id,
                'code' => $owner->code,
                'name' => $owner->name.(isset($owner->color) && $owner->color ? ' ('.$owner->color.')' : ''),
                'unit' => $owner->unit?->code,
                'count' => $lines->reject(fn ($line) => $line['is_return'])->pluck('doc')->unique()->count(),
                'quantity' => round($qty, 2),
                'dpp' => round($dpp, 2),
                'average_price' => $qty > 0 ? round($dpp / $qty, 2) : 0,
                'cost' => round($lines->sum('cost'), 2),
            ];
        })->map(fn ($row) => self::withProfit($row))->sortByDesc('dpp')->values()->pipe(fn ($rows) => $this->withShare($rows));
    }

    private function byMonth(Collection $documents, string $dateColumn): Collection
    {
        return $documents->groupBy(fn ($doc) => $doc->{$dateColumn}->format('Y-m'))->map(fn (Collection $docs, string $month) => [
            'key' => $month,
            'name' => Carbon::createFromFormat('Y-m-d', $month.'-01')->translatedFormat('F Y'),
            'count' => self::countInvoices($docs),
            'quantity' => round($docs->sum('quantity'), 2),
            'dpp' => round($docs->sum('dpp'), 2),
            'tax' => round($docs->sum(fn ($d) => (float) $d->tax_amount), 2),
            'total' => round($docs->sum(fn ($d) => (float) $d->total), 2),
            'paid' => round($docs->sum(fn ($d) => (float) $d->paid_amount), 2),
            'outstanding' => round($docs->sum('outstanding_amount'), 2),
            'cost' => round($docs->sum('cost'), 2),
        ])->map(fn ($row) => self::withProfit($row))->sortKeys()->values()->pipe(fn ($rows) => $this->withShare($rows));
    }

    /**
     * Tambahkan laba kotor (DPP − HPP) dan margin (% dari DPP).
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public static function withProfit(array $row): array
    {
        $profit = round((float) $row['dpp'] - (float) $row['cost'], 2);

        return $row + [
            'gross_profit' => $profit,
            'margin' => (float) $row['dpp'] != 0.0 ? round($profit / (float) $row['dpp'] * 100, 1) : 0.0,
        ];
    }

    private function withShare(Collection $rows): Collection
    {
        $total = $rows->sum('dpp');

        return $rows->map(fn ($row) => $row + ['share' => $total > 0 ? round($row['dpp'] / $total * 100, 1) : 0]);
    }

    /**
     * @param  array{rows: Collection, totals: array<string, float>}  $data
     */
    public function table(string $kind, array $data, string $groupBy, Carbon $from, Carbon $to, ?string $partyName = null, bool $withCost = false): ReportTable
    {
        $isSales = $kind === 'sales';
        $withCost = $withCost && $isSales;
        $profitColumns = $withCost ? [
            'cost' => ['HPP', 'money'],
            'gross_profit' => ['Laba Kotor', 'money'],
            'margin' => ['Margin', 'percent'],
        ] : [];
        $title = $isSales ? 'Laporan Penjualan' : 'Laporan Pembelian';

        $columns = match ($groupBy) {
            'item' => [
                'code' => ['Kode', 'text'],
                'name' => [$isSales ? 'Produk' : 'Bahan', 'text'],
                'unit' => ['Satuan', 'text'],
                'count' => [$isSales ? 'Invoice' : 'Tagihan', 'number'],
                'quantity' => ['Qty', 'qty'],
                'average_price' => ['Harga Rata-rata', 'money'],
                'dpp' => ['Nilai (DPP)', 'money'],
                ...$profitColumns,
                'share' => ['Kontribusi', 'percent'],
            ],
            default => [
                ...($groupBy === 'month'
                    ? ['name' => ['Bulan', 'text']]
                    : ['code' => ['Kode', 'text'], 'name' => [$isSales ? 'Pelanggan' : 'Supplier', 'text']]),
                'count' => [$isSales ? 'Invoice' : 'Tagihan', 'number'],
                // Qty pembelian berbeda-beda satuannya, jadi hanya ditampilkan untuk penjualan (pcs)
                ...($isSales ? ['quantity' => ['Qty (pcs)', 'qty']] : []),
                'dpp' => ['Nilai (DPP)', 'money'],
                ...$profitColumns,
                'tax' => ['PPN', 'money'],
                'total' => ['Total', 'money'],
                // Kolom terbayar dilepas saat ada HPP agar tabel tetap muat di PDF landscape
                ...($withCost ? [] : ['paid' => ['Terbayar', 'money']]),
                'outstanding' => ['Sisa', 'money'],
                'share' => ['Kontribusi', 'percent'],
            ],
        };

        $totals = array_intersect_key($data['totals'], $columns) + ['share' => $data['rows']->isNotEmpty() ? 100 : 0];
        if ($groupBy === 'item' && ! $isSales) {
            $totals['quantity'] = null;
        }

        $groupLabel = match ($groupBy) {
            'item' => $isSales ? 'Per Produk' : 'Per Bahan',
            'month' => 'Per Bulan',
            default => $isSales ? 'Per Pelanggan' : 'Per Supplier',
        };
        $subtitle = tanggal($from).' s/d '.tanggal($to).' · '.$groupLabel.($partyName ? ' · '.$partyName : '');

        return new ReportTable($title, $subtitle, $columns, $data['rows'], $totals, [
            [$isSales ? 'Penjualan (DPP)' : 'Pembelian (DPP)', rupiah($data['totals']['dpp'])],
            ...($data['totals']['returns'] > 0 ? [['Sudah dikurangi nota retur', rupiah($data['totals']['returns'])]] : []),
            ['Total termasuk PPN', rupiah($data['totals']['total'])],
            ['Belum dibayar', rupiah($data['totals']['outstanding'])],
            ...($withCost ? [
                ['HPP', rupiah($data['totals']['cost'])],
                ['Laba kotor', rupiah($data['totals']['gross_profit']).' ('.number_format($data['totals']['margin'], 1, ',', '.').'%)'],
            ] : []),
        ], 'landscape');
    }
}
