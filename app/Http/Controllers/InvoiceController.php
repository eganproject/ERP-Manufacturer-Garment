<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Services\InvoiceService;
use App\Support\DocumentPdf;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class InvoiceController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:receivables.view', only: ['index', 'show', 'pdf']),
            new Middleware('can:receivables.manage', only: ['create', 'store', 'cancel']),
        ];
    }

    public function index(Request $request)
    {
        $invoices = Invoice::with(['customer', 'salesOrder'])
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%"))
                ->orWhereHas('salesOrder', fn ($c) => $c->where('number', 'like', "%{$s}%"))))
            ->when($request->customer_id, fn ($q, $id) => $q->where('customer_id', $id))
            ->when($request->status === 'overdue', fn ($q) => $q->overdue())
            ->when($request->status && $request->status !== 'overdue', fn ($q) => $q->where('status', $request->status))
            ->latest('invoice_date')->latest('id')
            ->paginate(15)
            ->withQueryString();

        $summary = [
            'outstanding' => (float) Invoice::open()->selectRaw('COALESCE(SUM(total - paid_amount), 0) as v')->value('v'),
            'overdue' => (float) Invoice::overdue()->selectRaw('COALESCE(SUM(total - paid_amount), 0) as v')->value('v'),
            'overdue_count' => Invoice::overdue()->count(),
        ];

        $customers = Customer::orderBy('name')->pluck('name', 'id');

        return view('invoices.index', compact('invoices', 'summary', 'customers'));
    }

    public function create(Request $request)
    {
        $order = null;

        if ($request->sales_order_id) {
            $order = SalesOrder::with(['customer', 'items.variant.product'])->findOrFail($request->sales_order_id);

            if (! $order->canInvoice()) {
                return redirect()->route('sales-orders.show', $order)->with('error', 'Tidak ada barang terkirim yang belum ditagih pada SO ini.');
            }
        }

        // SO yang masih memiliki barang terkirim tetapi belum ditagih
        $billableOrders = SalesOrder::with('customer')
            ->whereHas('items', fn ($q) => $q->whereColumn('delivered_quantity', '>', 'invoiced_quantity'))
            ->latest('order_date')
            ->get();

        return view('invoices.create', [
            'order' => $order,
            'billableOrders' => $billableOrders,
            'defaultTaxRate' => Setting::invoice()['tax_rate'],
        ]);
    }

    public function store(Request $request, InvoiceService $service)
    {
        $data = $request->validate([
            'sales_order_id' => ['required', 'exists:sales_orders,id'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array'],
            'items.*' => ['nullable', 'numeric', 'min:0'],
        ], [], ['invoice_date' => 'tanggal invoice', 'due_date' => 'jatuh tempo', 'tax_rate' => 'PPN', 'items' => 'jumlah tagih']);

        $order = SalesOrder::with('customer')->findOrFail($data['sales_order_id']);
        $invoice = $service->createFromSalesOrder($order, $data['items'], $data);

        return redirect()->route('invoices.show', $invoice)->with('success', "Invoice {$invoice->number} berhasil dibuat.");
    }

    public function show(Invoice $invoice)
    {
        $invoice->load(['customer', 'salesOrder', 'items.variant.product', 'allocations.payment', 'creator', 'activities.user']);

        return view('invoices.show', compact('invoice'));
    }

    public function cancel(Request $request, Invoice $invoice, InvoiceService $service)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $service->cancel($invoice, $data['reason'] ?? null);

        return back()->with('success', 'Invoice dibatalkan, barang dapat ditagihkan ulang.');
    }

    public function pdf(Invoice $invoice)
    {
        $invoice->load(['customer', 'salesOrder.deliveries', 'items.variant.product.unit', 'creator']);

        return DocumentPdf::stream('pdf.invoice', ['invoice' => $invoice, 'settings' => Setting::invoice()], $invoice->number);
    }
}
