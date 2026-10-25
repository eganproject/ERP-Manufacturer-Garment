<?php

namespace App\Http\Controllers;

use App\Enums\SalesOrderStatus;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Services\DocumentNumber;
use App\Services\SalesService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SalesOrderController extends Controller
{
    public function index(Request $request)
    {
        $orders = SalesOrder::with('customer')
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%"))))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest('order_date')->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('sales-orders.index', compact('orders'));
    }

    public function create()
    {
        return view('sales-orders.form', $this->formData(new SalesOrder(['order_date' => now(), 'discount' => 0])));
    }

    public function store(Request $request)
    {
        [$data, $items] = $this->validated($request);

        $order = DB::transaction(function () use ($data, $items) {
            $order = SalesOrder::create($data + [
                'number' => DocumentNumber::generate(SalesOrder::class, 'SO', Carbon::parse($data['order_date'])),
                'status' => SalesOrderStatus::Draft,
                'created_by' => auth()->id(),
            ]);
            $order->items()->createMany($items);

            return $order;
        });

        return redirect()->route('sales-orders.show', $order)->with('success', "SO {$order->number} berhasil dibuat.");
    }

    public function show(SalesOrder $salesOrder)
    {
        $salesOrder->load(['customer', 'items.variant.product', 'productionOrders.product', 'creator']);

        return view('sales-orders.show', ['order' => $salesOrder]);
    }

    public function edit(SalesOrder $salesOrder)
    {
        abort_unless($salesOrder->isEditable(), 403, 'SO yang sudah dikonfirmasi tidak dapat diubah.');
        $salesOrder->load('items');

        return view('sales-orders.form', $this->formData($salesOrder));
    }

    public function update(Request $request, SalesOrder $salesOrder)
    {
        abort_unless($salesOrder->isEditable(), 403, 'SO yang sudah dikonfirmasi tidak dapat diubah.');
        [$data, $items] = $this->validated($request);

        DB::transaction(function () use ($salesOrder, $data, $items) {
            $salesOrder->update($data);
            $salesOrder->items()->delete();
            $salesOrder->items()->createMany($items);
        });

        return redirect()->route('sales-orders.show', $salesOrder)->with('success', 'SO berhasil diperbarui.');
    }

    public function destroy(SalesOrder $salesOrder)
    {
        abort_unless($salesOrder->isEditable(), 403, 'Hanya SO draft yang dapat dihapus.');
        $salesOrder->delete();

        return redirect()->route('sales-orders.index')->with('success', 'SO berhasil dihapus.');
    }

    public function confirm(SalesOrder $salesOrder)
    {
        abort_unless($salesOrder->isEditable(), 403);
        $salesOrder->update(['status' => SalesOrderStatus::Confirmed]);

        return back()->with('success', 'SO dikonfirmasi.');
    }

    public function deliver(SalesOrder $salesOrder, SalesService $service)
    {
        $service->deliver($salesOrder);

        return back()->with('success', 'SO dikirim, stok barang jadi berkurang.');
    }

    public function cancel(SalesOrder $salesOrder)
    {
        abort_unless(in_array($salesOrder->status, [SalesOrderStatus::Draft, SalesOrderStatus::Confirmed], true), 403);
        $salesOrder->update(['status' => SalesOrderStatus::Cancelled]);

        return back()->with('success', 'SO dibatalkan.');
    }

    private function formData(SalesOrder $order): array
    {
        return [
            'order' => $order,
            'customers' => Customer::where('is_active', true)->orderBy('name')->get(),
            'variants' => ProductVariant::with('product')
                ->whereHas('product', fn ($q) => $q->where('is_active', true))
                ->get()
                ->sortBy('display_name')
                ->values(),
        ];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'order_date' => ['required', 'date'],
            'delivery_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'exists:product_variants,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
        ], [
            'items.required' => 'Tambahkan minimal satu produk.',
        ]);

        $items = array_map(fn ($row) => [
            'product_variant_id' => $row['product_variant_id'],
            'quantity' => $row['quantity'],
            'price' => $row['price'],
            'subtotal' => round($row['quantity'] * $row['price'], 2),
        ], array_values($data['items']));

        unset($data['items']);

        $subtotal = collect($items)->sum('subtotal');
        $discount = min((float) ($data['discount'] ?? 0), $subtotal);

        return [array_merge($data, [
            'subtotal' => $subtotal,
            'discount' => $discount,
            'total' => $subtotal - $discount,
        ]), $items];
    }
}
