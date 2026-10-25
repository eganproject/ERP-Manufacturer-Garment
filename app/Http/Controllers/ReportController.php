<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Reports\ProductionReport;
use App\Services\Reports\StockValuationReport;
use App\Services\Reports\TradeReport;
use App\Support\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Laporan penjualan, pembelian, hasil produksi, dan nilai persediaan (tampilan + ekspor Excel/PDF).
 */
class ReportController extends Controller
{
    public function sales(Request $request, TradeReport $report)
    {
        return $this->trade($request, $report, 'sales');
    }

    public function purchases(Request $request, TradeReport $report)
    {
        return $this->trade($request, $report, 'purchases');
    }

    private function trade(Request $request, TradeReport $report, string $kind)
    {
        $request->validate($this->periodRules() + [
            'group' => ['nullable', Rule::in(array_keys(TradeReport::GROUPS))],
            'party_id' => ['nullable', 'integer'],
        ]);

        [$from, $to] = $this->period($request);
        $groupBy = $request->input('group', 'party');
        $partyId = $request->integer('party_id') ?: null;
        $parties = ($kind === 'sales' ? Customer::query() : Supplier::query())->orderBy('name')->pluck('name', 'id');

        $data = $report->build($kind, $from, $to, $groupBy, $partyId);

        if ($response = ReportExport::respond($report->table($kind, $data, $groupBy, $from, $to, $parties[$partyId] ?? null), $request->query('export'))) {
            return $response;
        }

        return view('reports.trade', $data + compact('kind', 'from', 'to', 'groupBy', 'partyId', 'parties'));
    }

    public function production(Request $request, ProductionReport $report)
    {
        $request->validate($this->periodRules() + [
            'product_id' => ['nullable', 'integer'],
            'detail' => ['nullable', Rule::in(['products', 'orders'])],
        ]);

        [$from, $to] = $this->period($request);
        $productId = $request->integer('product_id') ?: null;
        $detail = $request->input('detail', 'products');

        $data = $report->build($from, $to, $productId);

        if ($response = ReportExport::respond($report->table($data, $detail, $from, $to), $request->query('export'))) {
            return $response;
        }

        return view('reports.production', $data + [
            'from' => $from,
            'to' => $to,
            'productId' => $productId,
            'detail' => $detail,
            'productOptions' => Product::orderBy('name')->get()->mapWithKeys(fn ($p) => [$p->id => $p->code.' - '.$p->name]),
        ]);
    }

    public function stockValuation(Request $request, StockValuationReport $report)
    {
        $request->validate([
            'type' => ['nullable', Rule::in(array_keys(StockValuationReport::TYPES))],
            'category_id' => ['nullable', 'integer'],
        ]);

        $type = $request->input('type', 'materials');
        $categoryId = $request->integer('category_id') ?: null;
        $categoryOptions = Category::ofType($type === 'materials' ? 'material' : 'product')->orderBy('name')->pluck('name', 'id');

        $data = $report->build($type, $categoryId, $request->boolean('include_zero'));

        if ($response = ReportExport::respond($report->table($type, $data, $categoryOptions[$categoryId] ?? null), $request->query('export'))) {
            return $response;
        }

        return view('reports.stock-valuation', $data + compact('type', 'categoryId', 'categoryOptions'));
    }

    private function periodRules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'export' => ['nullable', Rule::in(['xlsx', 'pdf'])],
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(Request $request): array
    {
        return [
            $request->from ? Carbon::parse($request->from) : today()->startOfMonth(),
            $request->to ? Carbon::parse($request->to) : today(),
        ];
    }
}
