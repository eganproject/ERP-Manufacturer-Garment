<?php

namespace App\Services\Reports;

use App\Enums\SubcontractStatus;
use App\Models\SubcontractOrder;
use App\Models\SubcontractOrderMaterial;
use App\Support\ReportTable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Laporan maklon:
 * - Saldo bahan di vendor: bahan titipan yang masih dipegang vendor (order berjalan), dibanding pemakaian standar BOM.
 * - Rekap per vendor: order, hasil bagus/reject, ongkos jasa, dan susut bahan pada periode.
 */
class SubcontractReport
{
    public const VIEWS = ['balance' => 'Saldo Bahan di Vendor', 'vendors' => 'Rekap per Vendor'];

    /**
     * @return array{rows: Collection, totals: array<string, float|int>}
     */
    public function balance(?int $supplierId = null): array
    {
        $rows = SubcontractOrderMaterial::with(['material.unit', 'subcontractOrder.supplier', 'subcontractOrder.productionOrder.materials'])
            ->whereHas('subcontractOrder', fn ($q) => $q->where('status', SubcontractStatus::Open)
                ->when($supplierId, fn ($w, $id) => $w->where('supplier_id', $id)))
            ->where('sent_quantity', '>', 0)
            ->get()
            ->map(function (SubcontractOrderMaterial $line) {
                $order = $line->subcontractOrder;
                $standard = $line->standardUsage();
                $balance = $line->balance();

                return [
                    'vendor' => $order->supplier->name,
                    'supplier_id' => $order->supplier_id,
                    'order_id' => $order->id,
                    'order' => $order->number,
                    'spk' => $order->productionOrder->number,
                    'code' => $line->material->code,
                    'material' => $line->material->name.($line->material->color ? ' ('.$line->material->color.')' : ''),
                    'unit' => $line->material->unit?->code,
                    'sent' => (float) $line->sent_quantity,
                    'returned' => (float) $line->returned_quantity,
                    'balance' => $balance,
                    'standard_usage' => $standard,
                    // Sisa yang seharusnya masih ada di vendor setelah pemakaian standar
                    'expected_left' => round(max(0, $balance - $standard), 2),
                    'unit_cost' => (float) $line->unit_cost,
                    'value' => $line->balanceValue(),
                ];
            })
            ->sortBy(fn ($r) => $r['vendor'].'|'.$r['order'].'|'.$r['material'])
            ->values();

        return [
            'rows' => $rows,
            'totals' => [
                'vendors' => $rows->pluck('supplier_id')->unique()->count(),
                'orders' => $rows->pluck('order_id')->unique()->count(),
                'value' => round($rows->sum('value'), 2),
            ],
        ];
    }

    /**
     * @return array{rows: Collection, totals: array<string, float|int>}
     */
    public function vendors(Carbon $from, Carbon $to, ?int $supplierId = null): array
    {
        $orders = SubcontractOrder::with(['supplier', 'materials', 'productionOrder.materials', 'receipts'])
            ->where('status', '!=', SubcontractStatus::Cancelled)
            ->whereBetween('order_date', [$from->toDateString(), $to->toDateString().' 23:59:59'])
            ->when($supplierId, fn ($q, $id) => $q->where('supplier_id', $id))
            ->get();

        $rows = $orders->groupBy('supplier_id')->map(function (Collection $orders) {
            $vendor = $orders->first()->supplier;
            $received = (float) $orders->sum('received_quantity');
            $reject = (float) $orders->sum('reject_quantity');
            $closed = $orders->where('status', SubcontractStatus::Closed);

            // Susut: pemakaian aktual melebihi standar BOM, dinilai dengan harga pokok bahan (order yang sudah ditutup)
            $shrinkage = $closed->flatMap->materials->sum(fn (SubcontractOrderMaterial $m) => max(0, (float) $m->used_quantity - $m->standardUsage()) * (float) $m->unit_cost);

            return [
                'key' => $vendor->id,
                'code' => $vendor->code,
                'name' => $vendor->name,
                'count' => $orders->count(),
                'open' => $orders->where('status', SubcontractStatus::Open)->count(),
                'quantity' => (float) $orders->sum('quantity'),
                'received' => $received,
                'reject' => $reject,
                'reject_rate' => ProductionReport::rate($reject, $received + $reject),
                'service_cost' => round((float) $orders->sum('service_cost'), 2),
                'billed' => round($orders->flatMap->receipts->whereNotNull('bill_id')->sum('amount'), 2),
                'shrinkage' => round($shrinkage, 2),
                'on_time' => ProductionReport::rate(
                    $closed->filter(fn ($o) => ! $o->due_date || $o->closed_at->toDateString() <= $o->due_date->toDateString())->count(),
                    $closed->count(),
                ),
            ];
        })->sortByDesc('service_cost')->values();

        $received = (float) $rows->sum('received');
        $reject = (float) $rows->sum('reject');

        return [
            'rows' => $rows,
            'totals' => [
                'count' => $rows->sum('count'),
                'quantity' => $rows->sum('quantity'),
                'received' => $received,
                'reject' => $reject,
                'reject_rate' => ProductionReport::rate($reject, $received + $reject),
                'service_cost' => round($rows->sum('service_cost'), 2),
                'billed' => round($rows->sum('billed'), 2),
                'shrinkage' => round($rows->sum('shrinkage'), 2),
            ],
        ];
    }

    public function table(string $view, array $data, Carbon $from, Carbon $to, ?string $vendorName, bool $withCost): ReportTable
    {
        if ($view === 'balance') {
            $columns = [
                'vendor' => ['Vendor', 'text'],
                'order' => ['Order Maklon', 'text'],
                'spk' => ['SPK', 'text'],
                'material' => ['Bahan', 'text'],
                'unit' => ['Satuan', 'text'],
                'sent' => ['Dikirim', 'qty'],
                'returned' => ['Diretur', 'qty'],
                'balance' => ['Saldo di Vendor', 'qty'],
                'standard_usage' => ['Pemakaian Standar', 'qty'],
                'expected_left' => ['Seharusnya Sisa', 'qty'],
                ...($withCost ? ['value' => ['Nilai Saldo', 'money']] : []),
            ];

            return new ReportTable(
                'Saldo Bahan di Vendor Maklon',
                'per '.tanggal(today()).($vendorName ? ' · '.$vendorName : ''),
                $columns,
                $data['rows'],
                $withCost ? ['value' => $data['totals']['value']] : null,
                [
                    ['Vendor', (string) $data['totals']['vendors']],
                    ['Order berjalan', (string) $data['totals']['orders']],
                    ...($withCost ? [['Nilai bahan di vendor', rupiah($data['totals']['value'])]] : []),
                ],
                'landscape',
            );
        }

        $columns = [
            'code' => ['Kode', 'text'],
            'name' => ['Vendor', 'text'],
            'count' => ['Order', 'number'],
            'quantity' => ['Qty Order', 'qty'],
            'received' => ['Bagus', 'qty'],
            'reject' => ['Reject', 'qty'],
            'reject_rate' => ['% Reject', 'percent'],
            'service_cost' => ['Ongkos Jasa', 'money'],
            'billed' => ['Sudah Ditagih', 'money'],
            ...($withCost ? ['shrinkage' => ['Nilai Susut', 'money']] : []),
            'on_time' => ['% Tepat Waktu', 'percent'],
        ];

        $totals = collect($data['totals'])->only(array_keys($columns))->all();

        return new ReportTable(
            'Rekap Maklon per Vendor',
            tanggal($from).' – '.tanggal($to).($vendorName ? ' · '.$vendorName : ''),
            $columns,
            $data['rows'],
            $totals,
            [
                ['Order maklon', (string) $data['totals']['count']],
                ['Ongkos jasa', rupiah($data['totals']['service_cost'])],
                ['% Reject', number_format($data['totals']['reject_rate'], 1, ',', '.').'%'],
            ],
            'landscape',
        );
    }
}
