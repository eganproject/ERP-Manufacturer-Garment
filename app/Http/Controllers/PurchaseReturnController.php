<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Models\Account;
use App\Models\Bill;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\ReturnService;
use App\Support\DocumentPdf;
use App\Support\ReturnKind;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class PurchaseReturnController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:purchase.view', only: ['index', 'show', 'pdf']),
            new Middleware('can:purchase-returns.manage', only: ['create', 'store', 'cancel']),
            new Middleware('can:payables.manage', only: ['apply', 'refund']),
        ];
    }

    public function index(Request $request)
    {
        $kind = ReturnKind::purchases();
        $query = PurchaseReturn::query()
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")
                ->orWhereHas('supplier', fn ($c) => $c->where('name', 'like', "%{$s}%"))
                ->orWhereHas('purchaseOrder', fn ($c) => $c->where('number', 'like', "%{$s}%"))))
            ->when($request->party_id, fn ($q, $id) => $q->where('supplier_id', $id))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->from, fn ($q, $d) => $q->whereDate('return_date', '>=', $d))
            ->when($request->to, fn ($q, $d) => $q->whereDate('return_date', '<=', $d));

        $summary = (clone $query)->where('status', 'posted')
            ->selectRaw('COUNT(*) as count, COALESCE(SUM(total), 0) as total, COALESCE(SUM(total - applied_amount - refunded_amount), 0) as balance')
            ->first();

        $returns = $query->with(['supplier', 'purchaseOrder', 'items'])->latest('return_date')->latest('id')->paginate(15)->withQueryString();
        $parties = Supplier::orderBy('name')->pluck('name', 'id');

        return view('returns.index', compact('kind', 'returns', 'summary', 'parties'));
    }

    public function create(Request $request)
    {
        $kind = ReturnKind::purchases();
        $order = null;

        if ($request->order_id) {
            $order = PurchaseOrder::with(['supplier', 'items.material.unit', 'items.unit'])->findOrFail($request->order_id);

            if (! $order->canReturn()) {
                return redirect()->route('purchase-orders.show', $order)->with('error', 'Tidak ada barang diterima yang dapat diretur pada PO ini.');
            }
        }

        $orders = PurchaseOrder::with('supplier')
            ->where('status', '!=', 'cancelled')
            ->whereHas('items', fn ($q) => $q->whereColumn('received_quantity', '>', 'returned_quantity'))
            ->latest('order_date')
            ->get()
            ->mapWithKeys(fn ($o) => [$o->id => $o->number.' · '.$o->supplier->name]);

        $lines = $order?->items->map(fn ($item) => [
            'id' => $item->id,
            'name' => $item->material->display_name,
            'code' => $item->material->code,
            'unit' => $item->unitCode(),
            'shipped' => (float) $item->received_quantity,
            'returned' => (float) $item->returned_quantity,
            'returnable' => $item->returnableQuantity(),
            'unbilled' => $item->unbilledQuantity(),
            'stock' => (float) $item->material->stock,
            'price' => (float) $item->price,
        ]);

        return view('returns.create', compact('kind', 'order', 'orders', 'lines'));
    }

    public function store(Request $request, ReturnService $service)
    {
        $data = $this->validateReturn($request, 'purchase_orders');
        $order = PurchaseOrder::with('supplier')->findOrFail($data['order_id']);
        $return = $service->createPurchaseReturn($order, $data['items'], $data);

        return redirect()->route('purchase-returns.show', $return)->with('success', "Retur {$return->number} berhasil dicatat.");
    }

    public function show(PurchaseReturn $purchaseReturn)
    {
        $purchaseReturn->load(['supplier', 'purchaseOrder', 'items.material.unit', 'items.purchaseOrderItem.unit', 'applications.payable', 'creator', 'activities.user']);

        $openDocuments = $purchaseReturn->hasBalance()
            ? Bill::open()->where('supplier_id', $purchaseReturn->supplier_id)->orderBy('due_date')->get()
            : collect();

        return view('returns.show', [
            'kind' => ReturnKind::purchases(),
            'return' => $purchaseReturn,
            'openDocuments' => $openDocuments,
        ]);
    }

    public function cancel(Request $request, PurchaseReturn $purchaseReturn, ReturnService $service)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'alasan']);
        $service->cancel($purchaseReturn, $data['reason']);

        return back()->with('success', 'Retur dibatalkan, stok dan hutang dikembalikan.');
    }

    public function apply(Request $request, PurchaseReturn $purchaseReturn, ReturnService $service)
    {
        $data = $request->validate([
            'document_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'min:1'],
        ], [], ['document_id' => 'tagihan', 'amount' => 'jumlah']);
        $service->apply($purchaseReturn, $data['document_id'], (float) $data['amount']);

        return back()->with('success', 'Nota debit dipakai untuk mengurangi tagihan supplier.');
    }

    public function refund(Request $request, PurchaseReturn $purchaseReturn, ReturnService $service)
    {
        $service->refund($purchaseReturn, $this->validateRefund($request));

        return back()->with('success', 'Pengembalian dana dari supplier dicatat.');
    }

    public function pdf(PurchaseReturn $purchaseReturn)
    {
        $purchaseReturn->load(['supplier', 'purchaseOrder', 'items.material.unit', 'items.purchaseOrderItem.unit', 'applications.payable', 'creator']);

        return DocumentPdf::stream('pdf.return', ['kind' => ReturnKind::purchases(), 'return' => $purchaseReturn], $purchaseReturn->number);
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
        ], [], ['return_date' => 'tanggal retur', 'reason' => 'alasan retur', 'items' => 'jumlah retur']);
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
