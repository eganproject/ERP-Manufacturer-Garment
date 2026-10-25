<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Services\Accounting\FinancialReports;
use App\Services\Accounting\PeriodService;
use Illuminate\Http\Request;

/**
 * Tutup buku bulanan.
 */
class PeriodController extends Controller
{
    public function index(PeriodService $service, FinancialReports $reports)
    {
        $periods = $service->periods()->map(function ($period) use ($reports) {
            $pl = $reports->profitLoss($period->start(), $period->end());

            return ['period' => $period, 'revenue' => $pl['net_revenue'], 'net_profit' => $pl['net_profit']];
        });

        return view('accounting.periods', ['periods' => $periods]);
    }

    public function close(Request $request, PeriodService $service)
    {
        $data = $this->period($request) + $request->validate(['notes' => ['nullable', 'string', 'max:500']]);
        $period = $service->close($data['year'], $data['month'], $request->user(), $data['notes'] ?? null);

        return back()->with('success', "Periode {$period->label()} ditutup. Transaksi bertanggal s.d. {$period->end()->translatedFormat('d F Y')} terkunci.");
    }

    public function reopen(Request $request, PeriodService $service)
    {
        $data = $this->period($request) + $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $period = $service->reopen($data['year'], $data['month'], $data['reason']);

        return back()->with('success', "Periode {$period->label()} dibuka kembali.");
    }

    /**
     * @return array{year: int, month: int}
     */
    private function period(Request $request): array
    {
        $data = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        return ['year' => (int) $data['year'], 'month' => (int) $data['month']];
    }
}
