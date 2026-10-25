<?php

namespace App\Services\Reports;

use App\Enums\ProductionOrderStatus;
use App\Enums\ProductionStage as Stage;
use App\Models\ProductionOrder;
use App\Support\ReportTable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Laporan hasil produksi: SPK yang selesai pada periode, jumlah barang jadi, reject per artikel
 * dan per tahap, serta ketepatan waktu penyelesaian.
 */
class ProductionReport
{
    /**
     * @return array{orders: Collection, products: Collection, stages: Collection, totals: array<string, float|int>}
     */
    public function build(Carbon $from, Carbon $to, ?int $productId = null): array
    {
        $orders = ProductionOrder::with(['product', 'stages'])
            ->where('status', ProductionOrderStatus::Completed)
            ->whereBetween('completed_at', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->when($productId, fn ($q, $id) => $q->where('product_id', $id))
            ->orderBy('completed_at')
            ->get()
            ->each(function (ProductionOrder $order) {
                $order->on_time = $order->due_date === null || $order->completed_at->toDateString() <= $order->due_date->toDateString();
                $order->lead_days = (int) $order->start_date->diffInDays($order->completed_at->copy()->startOfDay());
                $order->reject_rate = self::rate((float) $order->reject_quantity, (float) $order->finished_quantity + (float) $order->reject_quantity);
            });

        $products = $orders->groupBy('product_id')->map(function (Collection $orders) {
            $product = $orders->first()->product;
            $planned = (float) $orders->sum('quantity');
            $finished = (float) $orders->sum('finished_quantity');
            $reject = (float) $orders->sum('reject_quantity');

            return [
                'key' => $product->id,
                'code' => $product->code,
                'name' => $product->name,
                'count' => $orders->count(),
                'planned' => $planned,
                'finished' => $finished,
                'reject' => $reject,
                'reject_rate' => self::rate($reject, $finished + $reject),
                'achievement' => self::rate($finished, $planned),
                'on_time' => self::rate($orders->where('on_time', true)->count(), $orders->count()),
                'unit_cost' => $finished > 0 ? round((float) $orders->sum('total_cost') / $finished, 2) : null,
            ];
        })->sortByDesc('finished')->values();

        // Reject per tahap, mengikuti urutan proses
        $stageLines = $orders->flatMap->stages;
        $stages = collect(Stage::cases())->map(function (Stage $stage) use ($stageLines) {
            $lines = $stageLines->where('stage', $stage);
            $output = (float) $lines->sum('output_quantity');
            $reject = (float) $lines->sum('reject_quantity');

            return [
                'stage' => $stage,
                'name' => $stage->label(),
                'output' => $output,
                'reject' => $reject,
                'reject_rate' => self::rate($reject, $output + $reject),
            ];
        })->filter(fn ($row) => $row['output'] > 0 || $row['reject'] > 0)->values();

        $planned = (float) $orders->sum('quantity');
        $finished = (float) $orders->sum('finished_quantity');
        $reject = (float) $orders->sum('reject_quantity');

        return [
            'orders' => $orders,
            'products' => $products,
            'stages' => $stages,
            'totals' => [
                'count' => $orders->count(),
                'planned' => $planned,
                'finished' => $finished,
                'reject' => $reject,
                'reject_rate' => self::rate($reject, $finished + $reject),
                'achievement' => self::rate($finished, $planned),
                'on_time' => self::rate($orders->where('on_time', true)->count(), $orders->count()),
                'lead_days' => $orders->isNotEmpty() ? round($orders->avg('lead_days'), 1) : 0,
                'total_cost' => (float) $orders->sum('total_cost'),
                'unit_cost' => $finished > 0 ? round((float) $orders->sum('total_cost') / $finished, 2) : null,
            ],
        ];
    }

    public static function rate(float $part, float $whole): float
    {
        return $whole > 0 ? round($part / $whole * 100, 1) : 0.0;
    }

    /**
     * @param  'products'|'orders'  $detail
     */
    public function table(array $data, string $detail, Carbon $from, Carbon $to): ReportTable
    {
        $totals = $data['totals'];

        if ($detail === 'orders') {
            $columns = [
                'number' => ['No. SPK', 'text'],
                'product' => ['Produk', 'text'],
                'start_date' => ['Mulai', 'date'],
                'due_date' => ['Target', 'date'],
                'completed_at' => ['Selesai', 'date'],
                'planned' => ['Rencana', 'qty'],
                'finished' => ['Jadi', 'qty'],
                'reject' => ['Reject', 'qty'],
                'reject_rate' => ['% Reject', 'percent'],
                'status' => ['Ketepatan', 'text'],
            ];
            $rows = $data['orders']->map(fn (ProductionOrder $o) => [
                'number' => $o->number,
                'product' => $o->product->code.' - '.$o->product->name,
                'start_date' => $o->start_date,
                'due_date' => $o->due_date,
                'completed_at' => $o->completed_at,
                'planned' => (float) $o->quantity,
                'finished' => (float) $o->finished_quantity,
                'reject' => (float) $o->reject_quantity,
                'reject_rate' => $o->reject_rate,
                'status' => $o->on_time ? 'Tepat waktu' : 'Terlambat',
            ]);
            $footer = ['planned' => $totals['planned'], 'finished' => $totals['finished'], 'reject' => $totals['reject'], 'reject_rate' => $totals['reject_rate']];
        } else {
            $columns = [
                'code' => ['Kode', 'text'],
                'name' => ['Produk', 'text'],
                'count' => ['SPK', 'number'],
                'planned' => ['Rencana', 'qty'],
                'finished' => ['Jadi', 'qty'],
                'reject' => ['Reject', 'qty'],
                'reject_rate' => ['% Reject', 'percent'],
                'achievement' => ['Pencapaian', 'percent'],
                'on_time' => ['Tepat Waktu', 'percent'],
                'unit_cost' => ['HPP / pcs', 'money'],
            ];
            $rows = $data['products'];
            $footer = array_intersect_key($totals, $columns);
        }

        return new ReportTable('Laporan Hasil Produksi', tanggal($from).' s/d '.tanggal($to), $columns, $rows, $footer, [
            ['SPK selesai', $totals['count'].' SPK'],
            ['Barang jadi', qty($totals['finished']).' pcs'],
            ['Reject', qty($totals['reject']).' pcs ('.number_format($totals['reject_rate'], 1, ',', '.').'%)'],
            ['Tepat waktu', number_format($totals['on_time'], 1, ',', '.').'%'],
        ], 'landscape');
    }
}
