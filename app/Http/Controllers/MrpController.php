<?php

namespace App\Http\Controllers;

use App\Enums\SalesOrderStatus;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Services\MrpService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Perencanaan kebutuhan bahan (MRP) dan pembuatan PO draft dari kekurangan bahan.
 */
class MrpController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:mrp.view'),
            new Middleware('can:purchase.manage', only: ['store']),
        ];
    }

    public function __construct(private MrpService $service) {}

    public function index(Request $request)
    {
        $request->validate([
            'sales_order_ids' => ['nullable', 'array'],
            'sales_order_ids.*' => ['integer'],
        ]);

        $salesOrderIds = array_values(array_filter(array_map('intval', $request->input('sales_order_ids', []))));
        $includeSafety = ! $request->has('filtered') || $request->boolean('safety');

        return view('mrp.index', $this->service->calculate($salesOrderIds ?: null, $includeSafety) + [
            'salesOrders' => SalesOrder::with('customer')->whereIn('status', SalesOrderStatus::open())->latest('order_date')->get(),
            'selectedSalesOrders' => $salesOrderIds,
            'includeSafety' => $includeSafety,
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'lines' => ['required', 'array'],
            'lines.*.selected' => ['nullable', 'boolean'],
            'lines.*.material_id' => ['required', 'exists:materials,id'],
            'lines.*.supplier_id' => ['nullable', 'exists:suppliers,id'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        $lines = array_values(array_filter($data['lines'], fn ($line) => ! empty($line['selected'])));
        $orders = $this->service->createPurchaseOrders(array_map(fn ($line) => $line + ['price' => 0], $lines), $data['notes'] ?? null);

        $message = 'Berhasil membuat '.$orders->count().' PO draft: '.$orders->pluck('number')->implode(', ').'. Periksa lalu tandai dipesan.';

        return $orders->count() === 1
            ? redirect()->route('purchase-orders.show', $orders->first())->with('success', $message)
            : redirect()->route('purchase-orders.index', ['status' => 'draft'])->with('success', $message);
    }
}
