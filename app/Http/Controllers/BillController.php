<?php

namespace App\Http\Controllers;

use App\Enums\SubcontractStatus;
use App\Models\Bill;
use App\Models\PurchaseOrder;
use App\Models\SubcontractOrder;
use App\Models\Supplier;
use App\Services\BillService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class BillController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:payables.view', only: ['index', 'show']),
            new Middleware('can:payables.manage', only: ['create', 'store', 'cancel']),
        ];
    }

    public function index(Request $request)
    {
        $bills = Bill::with(['supplier', 'purchaseOrder', 'subcontractOrder'])
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")
                ->orWhere('supplier_invoice_number', 'like', "%{$s}%")
                ->orWhereHas('supplier', fn ($c) => $c->where('name', 'like', "%{$s}%"))
                ->orWhereHas('purchaseOrder', fn ($c) => $c->where('number', 'like', "%{$s}%"))
                ->orWhereHas('subcontractOrder', fn ($c) => $c->where('number', 'like', "%{$s}%"))))
            ->when($request->supplier_id, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($request->status === 'overdue', fn ($q) => $q->overdue())
            ->when($request->status && $request->status !== 'overdue', fn ($q) => $q->where('status', $request->status))
            ->latest('bill_date')->latest('id')
            ->paginate(15)
            ->withQueryString();

        $summary = [
            'outstanding' => (float) Bill::open()->selectRaw('COALESCE(SUM(total - paid_amount - credited_amount), 0) as v')->value('v'),
            'overdue' => (float) Bill::overdue()->selectRaw('COALESCE(SUM(total - paid_amount - credited_amount), 0) as v')->value('v'),
            'due_soon' => (float) Bill::open()->whereBetween('due_date', [today(), today()->addDays(7)])->selectRaw('COALESCE(SUM(total - paid_amount - credited_amount), 0) as v')->value('v'),
        ];

        $suppliers = Supplier::orderBy('name')->pluck('name', 'id');

        return view('bills.index', compact('bills', 'summary', 'suppliers'));
    }

    public function create(Request $request)
    {
        if ($request->subcontract_order_id) {
            $subcontract = SubcontractOrder::with(['supplier', 'productionOrder.product', 'receipts' => fn ($q) => $q->whereNull('bill_id')->where('amount', '>', 0)])
                ->findOrFail($request->subcontract_order_id);

            if (! $subcontract->canBill()) {
                return redirect()->route('subcontract-orders.show', $subcontract)->with('error', 'Tidak ada penerimaan hasil maklon yang belum ditagih.');
            }

            return view('bills.create-subcontract', ['order' => $subcontract]);
        }

        $order = null;

        if ($request->purchase_order_id) {
            $order = PurchaseOrder::with(['supplier', 'items.material.unit'])->findOrFail($request->purchase_order_id);

            if (! $order->canBill()) {
                return redirect()->route('purchase-orders.show', $order)->with('error', 'Tidak ada barang diterima yang belum ditagih pada PO ini.');
            }
        }

        $billableOrders = PurchaseOrder::with('supplier')
            ->whereHas('items', fn ($q) => $q->whereRaw('received_quantity - billed_quantity - returned_quantity + returned_billed_quantity > 0'))
            ->latest('order_date')
            ->get();

        $billableSubcontracts = SubcontractOrder::with('supplier')
            ->where('status', '!=', SubcontractStatus::Cancelled)
            ->whereHas('receipts', fn ($q) => $q->whereNull('bill_id')->where('amount', '>', 0))
            ->latest('order_date')
            ->get();

        return view('bills.create', compact('order', 'billableOrders', 'billableSubcontracts'));
    }

    public function store(Request $request, BillService $service)
    {
        if ($request->filled('subcontract_order_id')) {
            return $this->storeSubcontract($request, $service);
        }

        $data = $request->validate([
            'purchase_order_id' => ['required', 'exists:purchase_orders,id'],
            'supplier_invoice_number' => ['nullable', 'string', 'max:50'],
            'bill_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:bill_date'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
        ], [], ['bill_date' => 'tanggal tagihan', 'due_date' => 'jatuh tempo', 'tax_rate' => 'PPN', 'items' => 'jumlah tagih', 'supplier_invoice_number' => 'no. faktur supplier']);

        $order = PurchaseOrder::with('supplier')->findOrFail($data['purchase_order_id']);
        $bill = $service->createFromPurchaseOrder($order, $data['items'], $data);

        return redirect()->route('bills.show', $bill)->with('success', "Tagihan {$bill->number} berhasil dicatat.");
    }

    private function storeSubcontract(Request $request, BillService $service)
    {
        $data = $request->validate([
            'subcontract_order_id' => ['required', 'exists:subcontract_orders,id'],
            'supplier_invoice_number' => ['nullable', 'string', 'max:50'],
            'bill_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:bill_date'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string'],
            'receipts' => ['required', 'array', 'min:1'],
            'receipts.*' => ['integer'],
        ], [], ['bill_date' => 'tanggal tagihan', 'due_date' => 'jatuh tempo', 'tax_rate' => 'PPN', 'receipts' => 'penerimaan hasil', 'supplier_invoice_number' => 'no. faktur vendor']);

        $order = SubcontractOrder::with('supplier')->findOrFail($data['subcontract_order_id']);
        $bill = $service->createFromSubcontract($order, $data['receipts'], $data);

        return redirect()->route('bills.show', $bill)->with('success', "Tagihan {$bill->number} berhasil dicatat.");
    }

    public function show(Bill $bill)
    {
        $bill->load(['supplier', 'purchaseOrder', 'subcontractOrder', 'items.material.unit', 'allocations.payment', 'creditApplications.source', 'creator', 'activities.user']);

        return view('bills.show', compact('bill'));
    }

    public function cancel(Request $request, Bill $bill, BillService $service)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $service->cancel($bill, $data['reason'] ?? null);

        return back()->with('success', 'Tagihan dibatalkan.');
    }
}
