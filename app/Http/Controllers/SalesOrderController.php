<?php

namespace App\Http\Controllers;

use App\Enums\SalesOrderStatus;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Services\DocumentNumber;
use App\Services\SalesService;
use App\Support\DocumentPdf;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SalesOrderController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.view', only: ['index', 'show', 'pdf', 'deliveryPdf']),
            new Middleware('can:sales.manage', only: ['create', 'store', 'edit', 'update', 'destroy', 'confirm', 'cancel']),
            new Middleware('can:sales.deliver', only: ['deliverForm', 'deliver']),
        ];
    }

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
            ActivityLog::record('created', "Membuat SO {$order->number}", $order);

            return $order;
        });

        return redirect()->route('sales-orders.show', $order)->with('success', "SO {$order->number} berhasil dibuat.");
    }

    public function show(SalesOrder $salesOrder)
    {
        $salesOrder->load(['customer', 'items.variant.product', 'productionOrders.product', 'deliveries.items.variant.product', 'invoices', 'creator', 'activities.user']);

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
            ActivityLog::record('updated', 'Mengubah SO', $salesOrder);
        });

        return redirect()->route('sales-orders.show', $salesOrder)->with('success', 'SO berhasil diperbarui.');
    }

    public function destroy(SalesOrder $salesOrder)
    {
        abort_unless($salesOrder->isEditable(), 403, 'Hanya SO draft yang dapat dihapus.');
        ActivityLog::record('deleted', "Menghapus SO {$salesOrder->number}");
        $salesOrder->delete();

        return redirect()->route('sales-orders.index')->with('success', 'SO berhasil dihapus.');
    }

    public function confirm(SalesOrder $salesOrder)
    {
        abort_unless($salesOrder->isEditable(), 403);
        $salesOrder->update(['status' => SalesOrderStatus::Confirmed]);
        ActivityLog::record('confirmed', 'Mengonfirmasi SO', $salesOrder);

        return back()->with('success', 'SO dikonfirmasi.');
    }

    public function deliverForm(SalesOrder $salesOrder)
    {
        abort_unless($salesOrder->canDeliver(), 403, 'SO ini tidak dapat dikirim.');
        $salesOrder->load(['customer', 'items.variant.product']);

        return view('sales-orders.deliver', ['order' => $salesOrder]);
    }

    public function deliver(Request $request, SalesOrder $salesOrder, SalesService $service)
    {
        $data = $request->validate([
            'delivery_date' => ['required', 'date'],
            'vehicle_number' => ['nullable', 'string', 'max:30'],
            'driver_name' => ['nullable', 'string', 'max:100'],
            'shipping_address' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array'],
            'items.*' => ['nullable', 'numeric', 'min:0'],
        ], [], ['delivery_date' => 'tanggal kirim', 'items' => 'jumlah kirim']);

        $delivery = $service->deliver($salesOrder, $data['items'], $data);

        return redirect()->route('sales-orders.show', $salesOrder)
            ->with('success', "Surat jalan {$delivery->number} tersimpan, stok barang jadi berkurang.");
    }

    public function cancel(SalesOrder $salesOrder)
    {
        abort_unless($salesOrder->canCancel(), 403, 'SO yang sudah ada pengiriman tidak dapat dibatalkan.');
        $salesOrder->update(['status' => SalesOrderStatus::Cancelled]);
        ActivityLog::record('cancelled', 'Membatalkan SO', $salesOrder);

        return back()->with('success', 'SO dibatalkan.');
    }

    public function pdf(SalesOrder $salesOrder)
    {
        $salesOrder->load(['customer', 'items.variant.product.unit', 'creator']);

        return DocumentPdf::stream('pdf.sales-order', ['order' => $salesOrder], $salesOrder->number);
    }

    public function deliveryPdf(Delivery $delivery)
    {
        $delivery->load(['salesOrder.customer', 'items.variant.product.unit', 'creator']);

        return DocumentPdf::stream('pdf.delivery', ['delivery' => $delivery], $delivery->number);
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
