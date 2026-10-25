<?php

namespace App\Http\Controllers;

use App\Enums\MarketplacePlatform;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\MarketplaceStore;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Reports\LaborReport;
use App\Services\Reports\MarketplaceReport;
use App\Services\Reports\ProductionReport;
use App\Services\Reports\StockValuationReport;
use App\Services\Reports\SubcontractReport;
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

        // HPP & laba kotor hanya untuk pengguna yang boleh melihat harga pokok
        $withCost = $kind === 'sales' && $request->user()->can('costing.view');
        $data = $report->build($kind, $from, $to, $groupBy, $partyId, $withCost);

        if ($response = ReportExport::respond($report->table($kind, $data, $groupBy, $from, $to, $parties[$partyId] ?? null, $withCost), $request->query('export'))) {
            return $response;
        }

        return view('reports.trade', $data + compact('kind', 'from', 'to', 'groupBy', 'partyId', 'parties', 'withCost'));
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

    public function subcontract(Request $request, SubcontractReport $report)
    {
        $request->validate($this->periodRules() + [
            'view' => ['nullable', Rule::in(array_keys(SubcontractReport::VIEWS))],
            'supplier_id' => ['nullable', 'integer'],
        ]);

        [$from, $to] = $this->period($request);
        $view = $request->input('view', 'balance');
        $supplierId = $request->integer('supplier_id') ?: null;
        $vendors = Supplier::subcontractors()->orderBy('name')->pluck('name', 'id');
        $withCost = $request->user()->can('costing.view');

        $data = $view === 'balance' ? $report->balance($supplierId) : $report->vendors($from, $to, $supplierId);

        if ($response = ReportExport::respond($report->table($view, $data, $from, $to, $vendors[$supplierId] ?? null, $withCost), $request->query('export'))) {
            return $response;
        }

        return view('reports.subcontract', $data + compact('view', 'from', 'to', 'supplierId', 'vendors', 'withCost'));
    }

    public function labor(Request $request, LaborReport $report)
    {
        $request->validate($this->periodRules() + [
            'view' => ['nullable', Rule::in(array_keys(LaborReport::VIEWS))],
            'employee_id' => ['nullable', 'integer'],
        ]);

        [$from, $to] = $this->period($request);
        $view = $request->input('view', 'employees');
        $employeeId = $request->integer('employee_id') ?: null;
        $employees = Employee::orderBy('name')->pluck('name', 'id');

        $data = $report->build($view, $from, $to, $employeeId);

        if ($response = ReportExport::respond($report->table($view, $data, $from, $to, $employees[$employeeId] ?? null), $request->query('export'))) {
            return $response;
        }

        return view('reports.labor', $data + compact('view', 'from', 'to', 'employeeId', 'employees'));
    }

    /** Penjualan & margin TikTok Shop / Shopee per platform & toko, per produk, atau per pesanan. */
    public function marketplace(Request $request, MarketplaceReport $report)
    {
        $request->validate($this->periodRules() + [
            'view' => ['nullable', Rule::in(array_keys(MarketplaceReport::VIEWS))],
            'platform' => ['nullable', Rule::enum(MarketplacePlatform::class)],
            'store_id' => ['nullable', 'integer'],
        ]);

        [$from, $to] = $this->period($request);
        $view = $request->input('view', 'stores');
        $platform = $request->input('platform');
        $storeId = $request->integer('store_id') ?: null;
        $withCost = $request->user()->can('costing.view');

        $data = $report->build($from, $to, $view, $platform, $storeId);

        if ($withCost && ($response = ReportExport::respond($report->table($data, $view, $from, $to), $request->query('export')))) {
            return $response;
        }

        return view('reports.marketplace', $data + [
            'from' => $from, 'to' => $to, 'view' => $view, 'platform' => $platform, 'storeId' => $storeId, 'withCost' => $withCost,
            'stores' => MarketplaceStore::options(false), 'platforms' => MarketplacePlatform::options(),
        ]);
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
