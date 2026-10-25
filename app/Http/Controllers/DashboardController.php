<?php

namespace App\Http\Controllers;

use App\Enums\ProductionOrderStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Models\Bill;
use App\Models\Invoice;
use App\Models\Material;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Services\Reports\DashboardMetrics;
use App\Services\Reports\StockValuationReport;
use App\Services\Reports\TradeReport;

class DashboardController extends Controller
{
    public function __invoke(DashboardMetrics $metrics, StockValuationReport $valuation, TradeReport $trade)
    {
        $user = auth()->user();

        $stats = [
            'open_sales' => SalesOrder::whereIn('status', SalesOrderStatus::open())->count(),
            'open_purchases' => PurchaseOrder::whereIn('status', [PurchaseOrderStatus::Ordered, PurchaseOrderStatus::Partial])->count(),
            'active_production' => ProductionOrder::where('status', ProductionOrderStatus::InProgress)->count(),
            'sales_this_month' => SalesOrder::whereIn('status', [SalesOrderStatus::Confirmed, SalesOrderStatus::Partial, SalesOrderStatus::Delivered])
                ->whereBetween('order_date', [now()->startOfMonth(), now()->endOfMonth()])
                ->sum('total'),
            'receivable_overdue' => (float) Invoice::overdue()->selectRaw('COALESCE(SUM(total - paid_amount), 0) as v')->value('v'),
            'payable_due' => (float) Bill::open()->whereDate('due_date', '<=', today()->addDays(7))->selectRaw('COALESCE(SUM(total - paid_amount), 0) as v')->value('v'),
        ];

        $overdueInvoices = Invoice::with('customer')->overdue()->orderBy('due_date')->limit(6)->get();
        $upcomingBills = Bill::with('supplier')->open()->whereDate('due_date', '<=', today()->addDays(7))->orderBy('due_date')->limit(6)->get();

        $lowStockMaterials = Material::with('unit')
            ->where('is_active', true)
            ->whereColumn('stock', '<=', 'min_stock')
            ->orderBy('stock')
            ->limit(8)
            ->get();

        $activeProduction = ProductionOrder::with(['product', 'stages'])
            ->where('status', ProductionOrderStatus::InProgress)
            ->orderBy('due_date')
            ->limit(8)
            ->get();

        $recentSales = SalesOrder::with('customer')->latest()->limit(5)->get();

        // Ringkasan tambahan, hanya dihitung bila pengguna boleh melihatnya
        $trend = $user->can('receivables.view') || $user->can('payables.view') ? $metrics->monthlyTrend() : collect();
        $receivables = $user->can('receivables.view') ? $metrics->openBalance(Invoice::class) : null;
        $payables = $user->can('payables.view') ? $metrics->openBalance(Bill::class) : null;
        $production = $user->can('production.view') ? $metrics->productionThisMonth() : null;
        $stockValue = $user->can('costing.view') ? $valuation->grandTotal() : null;
        $topCustomers = $user->can('receivables.view')
            ? $trade->build('sales', today()->startOfMonth(), today())['rows']->take(5)
            : collect();

        $invoiced = [
            'current' => (float) ($trend->last()['sales'] ?? 0),
            'previous' => (float) ($trend->slice(-2, 1)->first()['sales'] ?? 0),
        ];
        $invoiced['growth'] = DashboardMetrics::growth($invoiced['current'], $invoiced['previous']);

        return view('dashboard', compact(
            'stats', 'lowStockMaterials', 'activeProduction', 'recentSales', 'overdueInvoices', 'upcomingBills',
            'trend', 'receivables', 'payables', 'production', 'stockValue', 'topCustomers', 'invoiced',
        ));
    }
}
