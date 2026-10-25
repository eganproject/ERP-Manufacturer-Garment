<?php

namespace App\Services\Reports;

use App\Enums\InvoiceStatus;
use App\Enums\ProductionOrderStatus;
use App\Enums\ReturnStatus;
use App\Models\Bill;
use App\Models\Invoice;
use App\Models\ProductionOrder;
use App\Models\PurchaseReturn;
use App\Models\SalesReturn;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Angka ringkas untuk dashboard: tren penjualan/pembelian, posisi piutang/hutang, produksi bulan berjalan.
 */
class DashboardMetrics
{
    /**
     * Penjualan (invoice) dan pembelian (tagihan) per bulan, nilai DPP setelah dikurangi nota retur.
     *
     * @return Collection<int, array{month: string, label: string, sales: float, purchases: float}>
     */
    public function monthlyTrend(int $months = 6): Collection
    {
        $start = today()->startOfMonth()->subMonthsNoOverflow($months - 1);

        $sales = $this->monthlySums(Invoice::class, 'invoice_date', $start, SalesReturn::class);
        $purchases = $this->monthlySums(Bill::class, 'bill_date', $start, PurchaseReturn::class);

        return collect(range(0, $months - 1))->map(function (int $i) use ($start, $sales, $purchases) {
            $month = $start->copy()->addMonthsNoOverflow($i);
            $key = $month->format('Y-m');

            return [
                'month' => $key,
                'label' => $month->translatedFormat('M y'),
                'sales' => $sales[$key] ?? 0.0,
                'purchases' => $purchases[$key] ?? 0.0,
            ];
        });
    }

    /**
     * @return array<string, float>
     */
    private function monthlySums(string $model, string $dateColumn, Carbon $start, string $returnModel): array
    {
        // Dikelompokkan di PHP agar sama di SQLite maupun MySQL
        $sums = $model::query()
            ->where('status', '!=', InvoiceStatus::Cancelled)
            ->whereDate($dateColumn, '>=', $start)
            ->get([$dateColumn, 'subtotal', 'discount'])
            ->groupBy(fn ($doc) => $doc->{$dateColumn}->format('Y-m'))
            ->map(fn ($docs) => round($docs->sum(fn ($d) => (float) $d->subtotal - (float) $d->discount), 2))
            ->all();

        $returnModel::query()
            ->where('status', ReturnStatus::Posted)
            ->whereDate('return_date', '>=', $start)
            ->get(['return_date', 'amount'])
            ->groupBy(fn ($r) => $r->return_date->format('Y-m'))
            ->each(function ($returns, $month) use (&$sums) {
                $sums[$month] = round(($sums[$month] ?? 0) - $returns->sum(fn ($r) => (float) $r->amount), 2);
            });

        return $sums;
    }

    /**
     * Posisi piutang / hutang yang masih terbuka.
     *
     * @param  class-string<Invoice|Bill>  $model
     * @return array{outstanding: float, overdue: float, count: int}
     */
    public function openBalance(string $model): array
    {
        $open = $model::open()->get(['id', 'total', 'paid_amount', 'credited_amount', 'due_date', 'status']);

        return [
            'outstanding' => round($open->sum(fn ($d) => $d->outstanding()), 2),
            'overdue' => round($open->filter(fn ($d) => $d->isOverdue())->sum(fn ($d) => $d->outstanding()), 2),
            'count' => $open->count(),
        ];
    }

    /**
     * Hasil produksi bulan berjalan.
     *
     * @return array{finished: float, reject: float, reject_rate: float, count: int}
     */
    public function productionThisMonth(): array
    {
        $orders = ProductionOrder::where('status', ProductionOrderStatus::Completed)
            ->whereBetween('completed_at', [today()->startOfMonth(), today()->endOfDay()])
            ->get(['finished_quantity', 'reject_quantity']);

        $finished = (float) $orders->sum('finished_quantity');
        $reject = (float) $orders->sum('reject_quantity');

        return [
            'finished' => $finished,
            'reject' => $reject,
            'reject_rate' => ProductionReport::rate($reject, $finished + $reject),
            'count' => $orders->count(),
        ];
    }

    /** Persentase perubahan, null bila pembanding nol. */
    public static function growth(float $current, float $previous): ?float
    {
        return $previous > 0 ? round(($current - $previous) / $previous * 100, 1) : null;
    }
}
