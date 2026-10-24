<?php

namespace App\Http\Controllers;

use App\Enums\ProductionOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\StageStatus;
use App\Models\Bom;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductionStage;
use App\Models\SalesOrder;
use App\Services\DocumentNumber;
use App\Services\ProductionService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductionOrderController extends Controller
{
    public function __construct(private ProductionService $service) {}

    public function index(Request $request)
    {
        $orders = ProductionOrder::with(['product', 'salesOrder', 'stages'])
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")
                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$s}%"))))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest('start_date')->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('production-orders.index', compact('orders'));
    }

    public function create(Request $request)
    {
        $order = new ProductionOrder([
            'start_date' => now(),
            'product_id' => $request->integer('product_id') ?: null,
            'sales_order_id' => $request->integer('sales_order_id') ?: null,
        ]);

        return view('production-orders.form', $this->formData($order));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $order = DB::transaction(function () use ($data) {
            $order = ProductionOrder::create(collect($data)->except('items')->all() + [
                'number' => DocumentNumber::generate(ProductionOrder::class, 'SPK', Carbon::parse($data['start_date'])),
                'status' => ProductionOrderStatus::Draft,
                'created_by' => auth()->id(),
            ]);
            $this->service->syncItems($order, $data['items']);

            return $order;
        });

        return redirect()->route('production-orders.show', $order)->with('success', "SPK {$order->number} berhasil dibuat.");
    }

    public function show(ProductionOrder $productionOrder)
    {
        $productionOrder->load(['product.unit', 'bom', 'salesOrder.customer', 'items.variant', 'materials.material.unit', 'stages', 'creator']);

        return view('production-orders.show', [
            'order' => $productionOrder,
            'stageStatuses' => StageStatus::cases(),
        ]);
    }

    public function edit(ProductionOrder $productionOrder)
    {
        abort_unless($productionOrder->isEditable(), 403, 'SPK yang sudah berjalan tidak dapat diubah.');
        $productionOrder->load('items');

        return view('production-orders.form', $this->formData($productionOrder));
    }

    public function update(Request $request, ProductionOrder $productionOrder)
    {
        abort_unless($productionOrder->isEditable(), 403, 'SPK yang sudah berjalan tidak dapat diubah.');
        $data = $this->validated($request);

        DB::transaction(function () use ($productionOrder, $data) {
            $productionOrder->update(collect($data)->except('items')->all());
            $this->service->syncItems($productionOrder->fresh('product'), $data['items']);
        });

        return redirect()->route('production-orders.show', $productionOrder)->with('success', 'SPK berhasil diperbarui.');
    }

    public function destroy(ProductionOrder $productionOrder)
    {
        abort_unless($productionOrder->isEditable(), 403, 'Hanya SPK draft yang dapat dihapus.');
        $productionOrder->delete();

        return redirect()->route('production-orders.index')->with('success', 'SPK berhasil dihapus.');
    }

    public function start(ProductionOrder $productionOrder)
    {
        $this->service->start($productionOrder);

        return back()->with('success', 'Produksi dimulai, bahan baku sudah dikeluarkan dari gudang.');
    }

    public function updateStage(Request $request, ProductionOrder $productionOrder, ProductionStage $stage)
    {
        abort_unless($stage->production_order_id === $productionOrder->id, 404);

        $data = $request->validate([
            'status' => ['required', Rule::enum(StageStatus::class)],
            'output_quantity' => ['nullable', 'numeric', 'min:0'],
            'reject_quantity' => ['nullable', 'numeric', 'min:0'],
            'pic' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $this->service->updateStage($stage, $data);

        return back()->with('success', 'Tahapan '.$stage->stage->label().' diperbarui.');
    }

    public function complete(Request $request, ProductionOrder $productionOrder)
    {
        $data = $request->validate([
            'finished' => ['required', 'array'],
            'finished.*' => ['nullable', 'numeric', 'min:0'],
            'reject_quantity' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->service->complete($productionOrder, $data['finished'], (float) ($data['reject_quantity'] ?? 0));

        return back()->with('success', 'Produksi selesai, barang jadi masuk ke stok.');
    }

    public function cancel(ProductionOrder $productionOrder)
    {
        abort_unless($productionOrder->isEditable(), 403, 'SPK yang sudah berjalan tidak dapat dibatalkan.');
        $productionOrder->update(['status' => ProductionOrderStatus::Cancelled]);

        return back()->with('success', 'SPK dibatalkan.');
    }

    private function formData(ProductionOrder $order): array
    {
        $products = Product::with(['variants', 'activeBom.items.material.unit'])
            ->where('is_active', true)
            ->whereHas('boms', fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get();

        return [
            'order' => $order,
            'products' => $products,
            'salesOrders' => SalesOrder::with(['customer', 'items'])
                ->where('status', SalesOrderStatus::Confirmed)
                ->orWhere('id', $order->sales_order_id)
                ->latest()
                ->get(),
            'existingItems' => $order->exists ? $order->items->pluck('quantity', 'product_variant_id') : collect(),
        ];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'sales_order_id' => ['nullable', 'exists:sales_orders,id'],
            'start_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array'],
            'items.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $bom = Bom::where('product_id', $data['product_id'])->where('is_active', true)->latest()->first();

        if (! $bom) {
            throw ValidationException::withMessages(['product_id' => 'Produk ini belum memiliki BOM aktif.']);
        }

        return $data + ['bom_id' => $bom->id];
    }
}
