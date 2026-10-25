<?php

namespace App\Http\Controllers;

use App\Enums\PurchaseOrderStatus;
use App\Models\Material;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\DocumentNumber;
use App\Services\PurchaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PurchaseOrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = PurchaseOrder::with('supplier')
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")
                ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$s}%"))))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest('order_date')->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('purchase-orders.index', compact('orders'));
    }

    public function create()
    {
        return view('purchase-orders.form', $this->formData(new PurchaseOrder([
            'order_date' => now(),
        ])));
    }

    public function store(Request $request)
    {
        [$data, $items] = $this->validated($request);

        $order = DB::transaction(function () use ($data, $items) {
            $order = PurchaseOrder::create($data + [
                'number' => DocumentNumber::generate(PurchaseOrder::class, 'PO', Carbon::parse($data['order_date'])),
                'status' => PurchaseOrderStatus::Draft,
                'total' => collect($items)->sum('subtotal'),
                'created_by' => auth()->id(),
            ]);
            $order->items()->createMany($items);

            return $order;
        });

        return redirect()->route('purchase-orders.show', $order)->with('success', "PO {$order->number} berhasil dibuat.");
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load(['supplier', 'items.material.unit', 'receipts.items.material', 'creator']);

        return view('purchase-orders.show', ['order' => $purchaseOrder]);
    }

    public function edit(PurchaseOrder $purchaseOrder)
    {
        abort_unless($purchaseOrder->isEditable(), 403, 'PO yang sudah dipesan tidak dapat diubah.');
        $purchaseOrder->load('items');

        return view('purchase-orders.form', $this->formData($purchaseOrder));
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder)
    {
        abort_unless($purchaseOrder->isEditable(), 403, 'PO yang sudah dipesan tidak dapat diubah.');
        [$data, $items] = $this->validated($request);

        DB::transaction(function () use ($purchaseOrder, $data, $items) {
            $purchaseOrder->update($data + ['total' => collect($items)->sum('subtotal')]);
            $purchaseOrder->items()->delete();
            $purchaseOrder->items()->createMany($items);
        });

        return redirect()->route('purchase-orders.show', $purchaseOrder)->with('success', 'PO berhasil diperbarui.');
    }

    public function destroy(PurchaseOrder $purchaseOrder)
    {
        abort_unless($purchaseOrder->isEditable(), 403, 'Hanya PO draft yang dapat dihapus.');
        $purchaseOrder->delete();

        return redirect()->route('purchase-orders.index')->with('success', 'PO berhasil dihapus.');
    }

    public function submit(PurchaseOrder $purchaseOrder)
    {
        abort_unless($purchaseOrder->isEditable(), 403);
        $purchaseOrder->update(['status' => PurchaseOrderStatus::Ordered]);

        return back()->with('success', 'PO ditandai sudah dipesan ke supplier.');
    }

    public function cancel(PurchaseOrder $purchaseOrder)
    {
        abort_unless(in_array($purchaseOrder->status, [PurchaseOrderStatus::Draft, PurchaseOrderStatus::Ordered], true), 403, 'PO yang sudah ada penerimaan tidak dapat dibatalkan.');
        $purchaseOrder->update(['status' => PurchaseOrderStatus::Cancelled]);

        return back()->with('success', 'PO dibatalkan.');
    }

    public function receiveForm(PurchaseOrder $purchaseOrder)
    {
        abort_unless($purchaseOrder->canReceive(), 403, 'PO ini tidak dapat diterima.');
        $purchaseOrder->load(['supplier', 'items.material.unit']);

        return view('purchase-orders.receive', ['order' => $purchaseOrder]);
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder, PurchaseService $service)
    {
        $data = $request->validate([
            'receipt_date' => ['required', 'date'],
            'delivery_note' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array'],
            'items.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $receipt = $service->receive($purchaseOrder, $data['items'], $data);

        return redirect()->route('purchase-orders.show', $purchaseOrder)
            ->with('success', "Penerimaan {$receipt->number} tersimpan, stok bahan bertambah.");
    }

    private function formData(PurchaseOrder $order): array
    {
        return [
            'order' => $order,
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(),
            'materials' => Material::with('unit')->where('is_active', true)->orderBy('name')->get(),
        ];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')],
            'order_date' => ['required', 'date'],
            'expected_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.material_id' => ['required', 'exists:materials,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
        ], [
            'items.required' => 'Tambahkan minimal satu bahan.',
        ]);

        $items = array_map(fn ($row) => [
            'material_id' => $row['material_id'],
            'quantity' => $row['quantity'],
            'price' => $row['price'],
            'subtotal' => round($row['quantity'] * $row['price'], 2),
        ], array_values($data['items']));

        unset($data['items']);

        return [$data, $items];
    }
}
