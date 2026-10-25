<?php

namespace App\Http\Controllers;

use App\Enums\ProductionCostType;
use App\Enums\ProductionOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\StageStatus;
use App\Enums\SubcontractStatus;
use App\Models\ActivityLog;
use App\Models\Bom;
use App\Models\Product;
use App\Models\ProductionCost;
use App\Models\ProductionOrder;
use App\Models\ProductionStage;
use App\Models\SalesOrder;
use App\Services\Accounting\AutoJournal;
use App\Services\DocumentNumber;
use App\Services\PieceworkService;
use App\Services\ProductionService;
use App\Services\SubcontractService;
use App\Support\DocumentPdf;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductionOrderController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:production.view', only: ['index', 'show', 'pdf']),
            new Middleware('can:production.manage', except: ['index', 'show', 'pdf']),
        ];
    }

    public function __construct(private ProductionService $service, private SubcontractService $subcontracts) {}

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
            ActivityLog::record('created', "Membuat SPK {$order->number}", $order);

            return $order;
        });

        return redirect()->route('production-orders.show', $order)->with('success', "SPK {$order->number} berhasil dibuat.");
    }

    public function show(ProductionOrder $productionOrder)
    {
        $productionOrder->load(['product.unit', 'bom.items.material', 'salesOrder.customer', 'items.variant', 'materials.material.unit', 'stages.subcontractOrder.supplier', 'costs', 'creator', 'activities.user', 'subcontractOrders.supplier', 'pieceworkSheets.entries', 'product.operations']);

        return view('production-orders.show', [
            'order' => $productionOrder,
            'stageStatuses' => StageStatus::cases(),
            'costTypes' => ProductionCostType::options(),
            'pieceworkProgress' => app(PieceworkService::class)->progress($productionOrder),
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
            ActivityLog::record('updated', 'Mengubah SPK', $productionOrder);
        });

        return redirect()->route('production-orders.show', $productionOrder)->with('success', 'SPK berhasil diperbarui.');
    }

    public function destroy(ProductionOrder $productionOrder)
    {
        abort_unless($productionOrder->isEditable(), 403, 'Hanya SPK draft yang dapat dihapus.');
        $productionOrder->subcontractOrders()->each(fn ($order) => $order->delete());
        ActivityLog::record('deleted', "Menghapus SPK {$productionOrder->number}");
        $productionOrder->delete();

        return redirect()->route('production-orders.index')->with('success', 'SPK berhasil dihapus.');
    }

    public function start(ProductionOrder $productionOrder)
    {
        $this->service->start($productionOrder);

        return back()->with('success', $productionOrder->subcontractOrders()->exists()
            ? 'Produksi dimulai. Bahan internal sudah dikeluarkan; bahan untuk vendor maklon dikirim lewat order maklon.'
            : 'Produksi dimulai, bahan baku sudah dikeluarkan dari gudang.');
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

    public function storeCost(Request $request, ProductionOrder $productionOrder)
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(ProductionCostType::class)],
            'description' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'date' => ['required', 'date'],
        ]);

        $this->service->addCost($productionOrder, $data);

        return back()->with('success', 'Biaya produksi dicatat.');
    }

    public function destroyCost(ProductionOrder $productionOrder, ProductionCost $cost)
    {
        abort_unless($cost->production_order_id === $productionOrder->id, 404);
        $this->service->removeCost($cost);

        return back()->with('success', 'Biaya produksi dihapus.');
    }

    public function cancel(ProductionOrder $productionOrder)
    {
        abort_unless($productionOrder->isEditable(), 403, 'SPK yang sudah berjalan tidak dapat dibatalkan.');
        DB::transaction(function () use ($productionOrder) {
            $productionOrder->subcontractOrders()->where('status', SubcontractStatus::Open)->get()
                ->each(fn ($order) => $this->subcontracts->cancel($order, 'SPK dibatalkan'));
            $productionOrder->update(['status' => ProductionOrderStatus::Cancelled]);
            // Biaya yang sempat dicatat saat draft dibalik dari barang dalam proses
            app(AutoJournal::class)->reverse($productionOrder, 'SPK dibatalkan');
        });
        ActivityLog::record('cancelled', 'Membatalkan SPK', $productionOrder);

        return back()->with('success', 'SPK dibatalkan.');
    }

    public function pdf(ProductionOrder $productionOrder)
    {
        $productionOrder->load(['product.unit', 'bom', 'salesOrder.customer', 'items.variant', 'materials.material.unit', 'stages', 'creator']);

        return DocumentPdf::stream('pdf.production-order', ['order' => $productionOrder], $productionOrder->number);
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
                ->whereIn('status', SalesOrderStatus::open())
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
