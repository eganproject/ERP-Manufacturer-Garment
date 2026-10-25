<?php

namespace App\Services\Reports;

use App\Models\PieceworkEntry;
use App\Support\ReportTable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Laporan upah borongan berdasarkan tanggal hasil kerja:
 * - per karyawan: hasil bagus, reject QC, % reject, upah, dan yang sudah / belum dibayar;
 * - per SPK: upah borongan yang dibebankan dan upah per pcs rencana.
 */
class LaborReport
{
    public const VIEWS = ['employees' => 'Per Karyawan', 'orders' => 'Per SPK'];

    /**
     * @return array{rows: Collection, totals: array<string, float|int>}
     */
    public function build(string $view, Carbon $from, Carbon $to, ?int $employeeId = null): array
    {
        $entries = PieceworkEntry::with(['employee', 'sheet.productionOrder.product', 'payrollLine.payroll'])
            ->whereHas('sheet', fn ($q) => $q->whereBetween('date', [$from->toDateString(), $to->toDateString()]))
            ->when($employeeId, fn ($q, $id) => $q->where('employee_id', $id))
            ->get();

        $rows = $view === 'orders' ? $this->byOrder($entries) : $this->byEmployee($entries);
        $good = (float) $entries->sum('quantity');
        $reject = (float) $entries->sum('reject_quantity');

        return [
            'rows' => $rows,
            'totals' => [
                'employees' => $entries->pluck('employee_id')->unique()->count(),
                'orders' => $entries->pluck('sheet.production_order_id')->unique()->count(),
                'good' => $good,
                'reject' => $reject,
                'reject_rate' => ProductionReport::rate($reject, $good + $reject),
                'amount' => round((float) $entries->sum('amount'), 2),
                'paid' => round((float) $rows->sum('paid'), 2),
                'unpaid' => round((float) $rows->sum('unpaid'), 2),
            ],
        ];
    }

    private function byEmployee(Collection $entries): Collection
    {
        return $entries->groupBy('employee_id')->map(function (Collection $rows) {
            $employee = $rows->first()->employee;
            $good = (float) $rows->sum('quantity');
            $reject = (float) $rows->sum('reject_quantity');
            $paid = (float) $rows->filter(fn ($e) => $e->payrollLine?->payroll?->status?->value === 'paid')->sum('amount');

            return [
                'key' => $employee->id,
                'code' => $employee->code,
                'name' => $employee->name,
                'type' => $employee->work_type->label(),
                'days' => $rows->pluck('sheet.date')->map->toDateString()->unique()->count(),
                'good' => $good,
                'reject' => $reject,
                'reject_rate' => ProductionReport::rate($reject, $good + $reject),
                'amount' => round((float) $rows->sum('amount'), 2),
                'paid' => round($paid, 2),
                'unpaid' => round((float) $rows->sum('amount') - $paid, 2),
            ];
        })->sortByDesc('amount')->values();
    }

    private function byOrder(Collection $entries): Collection
    {
        return $entries->groupBy('sheet.production_order_id')->map(function (Collection $rows) {
            $spk = $rows->first()->sheet->productionOrder;
            $amount = (float) $rows->sum('amount');
            $paid = (float) $rows->filter(fn ($e) => $e->payrollLine?->payroll?->status?->value === 'paid')->sum('amount');

            return [
                'key' => $spk->id,
                'number' => $spk->number,
                'product' => $spk->product->name,
                'quantity' => (float) $spk->quantity,
                'employees' => $rows->pluck('employee_id')->unique()->count(),
                'good' => (float) $rows->sum('quantity'),
                'reject' => (float) $rows->sum('reject_quantity'),
                'amount' => round($amount, 2),
                'per_pcs' => (float) $spk->quantity > 0 ? round($amount / (float) $spk->quantity, 2) : 0.0,
                'paid' => round($paid, 2),
                'unpaid' => round($amount - $paid, 2),
            ];
        })->sortByDesc('amount')->values();
    }

    public function table(string $view, array $data, Carbon $from, Carbon $to, ?string $employeeName): ReportTable
    {
        $columns = $view === 'orders'
            ? [
                'number' => ['SPK', 'text'],
                'product' => ['Produk', 'text'],
                'quantity' => ['Qty SPK', 'qty'],
                'employees' => ['Karyawan', 'number'],
                'good' => ['Hasil Bagus', 'qty'],
                'reject' => ['Reject', 'qty'],
                'amount' => ['Upah Borongan', 'money'],
                'per_pcs' => ['Upah / pcs', 'money'],
                'paid' => ['Sudah Dibayar', 'money'],
                'unpaid' => ['Belum Dibayar', 'money'],
            ]
            : [
                'code' => ['Kode', 'text'],
                'name' => ['Karyawan', 'text'],
                'type' => ['Pola Kerja', 'text'],
                'days' => ['Hari Kerja', 'number'],
                'good' => ['Hasil Bagus', 'qty'],
                'reject' => ['Reject', 'qty'],
                'reject_rate' => ['% Reject', 'percent'],
                'amount' => ['Upah', 'money'],
                'paid' => ['Sudah Dibayar', 'money'],
                'unpaid' => ['Belum Dibayar', 'money'],
            ];

        return new ReportTable(
            'Laporan Upah Borongan '.self::VIEWS[$view],
            tanggal($from).' – '.tanggal($to).($employeeName ? ' · '.$employeeName : ''),
            $columns,
            $data['rows'],
            collect($data['totals'])->only(['good', 'reject', 'amount', 'paid', 'unpaid'])->all(),
            [
                ['Karyawan', (string) $data['totals']['employees']],
                ['Total upah', rupiah($data['totals']['amount'])],
                ['% Reject', number_format($data['totals']['reject_rate'], 1, ',', '.').'%'],
            ],
            'landscape',
        );
    }
}
