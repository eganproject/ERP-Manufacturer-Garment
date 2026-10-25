<?php

namespace App\Http\Controllers\Assets;

use App\Http\Controllers\Controller;
use App\Models\DepreciationRun;
use App\Services\DepreciationService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;

/**
 * Posting penyusutan aset tetap bulanan (PNY-).
 */
class DepreciationController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:assets.view', only: ['index', 'show']),
            new Middleware('can:assets.manage', only: ['store', 'cancel']),
        ];
    }

    public function index(Request $request, DepreciationService $service)
    {
        $request->validate(['period' => ['nullable', 'date_format:Y-m']]);
        $last = $service->lastPostedPeriod();
        $default = $last ? $last->copy()->addMonthNoOverflow() : today()->startOfMonth()->subMonthNoOverflow();
        $period = $request->period ? Carbon::createFromFormat('Y-m', $request->period)->startOfMonth() : $default->min(today()->startOfMonth());

        return view('assets.depreciation.index', [
            'period' => $period,
            'last' => $last,
            'preview' => $service->preview($period),
            'runs' => DepreciationRun::latest('period')->latest('id')->paginate(12)->withQueryString(),
        ]);
    }

    public function store(Request $request, DepreciationService $service)
    {
        $data = $request->validate(['period' => ['required', 'date_format:Y-m']], [], ['period' => 'bulan']);
        $run = $service->run(Carbon::createFromFormat('Y-m', $data['period'])->startOfMonth());

        return redirect()->route('depreciation-runs.show', $run)
            ->with('success', "Penyusutan {$run->periodLabel()} diposting: {$run->asset_count} aset, ".rupiah($run->total).'.');
    }

    public function show(DepreciationRun $depreciationRun)
    {
        $depreciationRun->load(['lines.asset.category', 'creator', 'activities.user']);

        return view('assets.depreciation.show', ['run' => $depreciationRun]);
    }

    public function cancel(Request $request, DepreciationRun $depreciationRun, DepreciationService $service)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'alasan pembatalan']);
        $service->cancel($depreciationRun, $data['reason']);

        return back()->with('success', "Posting penyusutan {$depreciationRun->number} dibatalkan dan jurnalnya dibalik.");
    }
}
