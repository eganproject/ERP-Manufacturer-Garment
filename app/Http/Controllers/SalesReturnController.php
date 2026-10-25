<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\Warehouse;
use App\Services\ReturnService;
use App\Support\DocumentPdf;
use App\Support\ReturnKind;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class SalesReturnController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.view', only: ['index', 'show', 'pdf']),
            new Middleware('can:sales-returns.manage', only: ['create', 'store', 'cancel']),
            new Middleware('can:receivables.manage', only: ['apply', 'refund']),
        ];
    }

    public function index(Request $request)
    {
        $kind = ReturnKind::sales();
        $query = SalesReturn::query()
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%"))
                ->orWhereHas('salesOrder', fn ($c) => $c->where('number', 'like', "%{$s}%"))))
            ->when($request->party_id, fn ($q, $id) => $q->where('customer_id', $id))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->from, fn ($q, $d) => $q->whereDate('return_date', '>=', $d))
            ->when($request->to, fn ($q, $d) => $q->whereDate('return_date', '<=', $d));

        $summary = (clone $query)->where('status', 'posted')
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total), 0) as total, COALESCE(SUM(total - applied_amount - refunded_amount), 0) as balance')
            ->first();

        $returns = $query->with(['customer', 'salesOrder', 'items'])->latest('return_date')->latest('id')->paginate(15)->withQueryString();
        $parties = Customer::orderBy('name')->pluck('name', 'id');

        return view('returns.index', compact('kind', 'returns', 'summary', 'parties'));
    }

    public function create(Request $request)
    {
        $kind = ReturnKind::sales();
        $order = null;

        if ($request->order_id) {
            $order = SalesOrder::with(['customer', 'items.variant.product'])->findOrFail($request->order_id);

            if (! $order->canReturn()) {
                return redirect()->route('sales-orders.show', $order)->with('error', 'Tidak ada barang terkirim yang dapat diretur pada SO ini.');
            }
        }

        $orders = SalesOrder::with('customer')
            ->where('status', '!=', 'cancelled')
            ->whereHas('items', fn ($q) => $q->whereColumn('delivered_quantity', '>', 'returned_quantity'))
            ->latest('order_date')
            ->get()
            ->mapWithKeys(fn ($o) => [$o->id => $o->number.' · '.$o->customer->name]);

        $lines = $order?->items->map(fn ($item) => [
            'id' => $item->id,
            'name' => $item->variant->display_name,
            'code' => $item->variant->sku,
            'unit' => 'pcs',
            'shipped' => (float) $item->delivered_quantity,
            'returned' => (float) $item->returned_quantity,
            'returnable' => $item->returnableQuantity(),
            'unbilled' => $item->uninvoicedQuantity(),
            'price' => (float) $item->price,
        ]);

        return view('returns.create', compact('kind', 'order', 'orders', 'lines'));
    }

    public function store(Request $request, ReturnService $service)
    {
        $data = $this->validateReturn($request, 'sales_orders');
        $order = SalesOrder::with('customer')->findOrFail($data['order_id']);
        $return = $service->createSalesReturn($order, $data['items'], $data);

        return redirect()->route('sales-returns.show', $return)->with('success', "Retur {$return->number} berhasil dicatat.");
    }

    public function show(SalesReturn $salesReturn)
    {
        $salesReturn->load(['customer', 'salesOrder', 'items.variant.product', 'applications.payable', 'creator', 'activities.user']);

        $openDocuments = $salesReturn->hasBalance()
            ? Invoice::open()->where('customer_id', $salesReturn->customer_id)->orderBy('due_date')->get()
            : collect();

        return view('returns.show', [
            'kind' => ReturnKind::sales(),
            'return' => $salesReturn,
            'openDocuments' => $openDocuments,
        ]);
    }

    public function cancel(Request $request, SalesReturn $salesReturn, ReturnService $service)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'alasan']);
        $service->cancel($salesReturn, $data['reason']);

        return back()->with('success', 'Retur dibatalkan, stok dan piutang dikembalikan.');
    }

    public function apply(Request $request, SalesReturn $salesReturn, ReturnService $service)
    {
        $data = $request->validate([
            'document_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:1'],
        ], [], ['document_id' => 'invoice', 'amount' => 'jumlah']);
        $service->apply($salesReturn, $data['document_id'], (float) $data['amount']);

        return back()->with('success', 'Nota kredit dipakai untuk mengurangi invoice.');
    }

    public function refund(Request $request, SalesReturn $salesReturn, ReturnService $service)
    {
        $service->refund($salesReturn, $this->validateRefund($request));

        return back()->with('success', 'Pengembalian dana ke pelanggan dicatat.');
    }

    public function pdf(SalesReturn $salesReturn)
    {
        $salesReturn->load(['customer', 'salesOrder', 'items.variant.product.unit', 'applications.payable', 'creator']);

        return DocumentPdf::stream('pdf.return', ['kind' => ReturnKind::sales(), 'return' => $salesReturn], $salesReturn->number);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateReturn(Request $request, string $orderTable): array
    {
        return $request->validate([
            'order_id' => ['required', "exists:{$orderTable},id"],
            'return_date' => ['required', 'date'],
            'warehouse_id' => ['nullable', Warehouse::rule()],
            'reason' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array'],
            'items.*' => ['nullable', 'numeric', 'min:0'],
            'defects' => ['nullable', 'array'],
            'defects.*' => ['nullable', 'numeric', 'min:0'],
        ], [], ['return_date' => 'tanggal retur', 'reason' => 'alasan retur', 'items' => 'jumlah retur', 'defects.*' => 'jumlah cacat']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validateRefund(Request $request): array
    {
        return $request->validate([
            'refund_date' => ['required', 'date'],
            'refund_method' => ['required', Rule::enum(PaymentMethod::class)],
            'refund_reference' => ['nullable', 'string', 'max:100'],
            'refund_account_id' => ['nullable', Account::cashRule()],
            'amount' => ['required', 'numeric', 'min:1'],
        ], [], ['refund_date' => 'tanggal', 'refund_method' => 'metode', 'amount' => 'jumlah']);
    }
}
