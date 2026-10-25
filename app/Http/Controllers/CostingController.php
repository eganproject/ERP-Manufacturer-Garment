<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Services\CostingReport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Laporan HPP produksi & margin per artikel.
 */
class CostingController extends Controller
{
    public function __invoke(Request $request, CostingReport $report)
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'product_id' => ['nullable', 'integer'],
        ]);

        $from = $request->from ? Carbon::parse($request->from) : today()->startOfMonth();
        $to = $request->to ? Carbon::parse($request->to) : today();
        $productId = $request->integer('product_id') ?: null;

        return view('reports.costing', $report->build($from, $to, $productId) + [
            'from' => $from,
            'to' => $to,
            'productId' => $productId,
            'productOptions' => Product::orderBy('name')->get()->mapWithKeys(fn ($p) => [$p->id => $p->code.' - '.$p->name]),
        ]);
    }
}
