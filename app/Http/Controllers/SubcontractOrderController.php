<?php

namespace App\Http\Controllers;

use App\Enums\ProductionOrderStatus;
use App\Enums\ProductionStage;
use App\Enums\SubcontractStatus;
use App\Enums\SubcontractTransferType;
use App\Models\ProductionOrder;
use App\Models\SubcontractOrder;
use App\Models\SubcontractReceipt;
use App\Models\SubcontractTransfer;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\SubcontractService;
use App\Support\DocumentPdf;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Order maklon: tahap SPK yang dikerjakan vendor luar dengan bahan kiriman perusahaan.
 */
class SubcontractOrderController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:production.view', only: ['index', 'show', 'pdf', 'transferPdf', 'receiptPdf']),
            new Middleware('can:subcontract.manage', only: ['create', 'store', 'close', 'cancel']),
            new Middleware('can:subcontract.transfer', only: ['transferForm', 'transfer', 'receiveForm', 'receive']),
        ];
    }

    public function __construct(private SubcontractService $service) {}

    public function index(Request $request)
    {
        $orders = SubcontractOrder::with(['supplier', 'productionOrder.product', 'materials'])
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")
                ->orWhereHas('productionOrder', fn ($p) => $p->where('number', 'like', "%{$s}%"))
                ->orWhereHas('supplier', fn ($p) => $p->where('name', 'like', "%{$s}%"))))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->supplier_id, fn ($q, $id) => $q->where('supplier_id', $id))
            ->latest('order_date')->latest('id')
            ->paginate(15)
            ->withQueryString();

        $open = SubcontractOrder::with('materials')->where('status', SubcontractStatus::Open)->get();
        $summary = [
            'open' => $open->count(),
            'outstanding' => $open->sum(fn (SubcontractOrder $o) => $o->outstandingQuantity()),
            'material_value' => round($open->flatMap->materials->sum(fn ($m) => $m->balanceValue()), 2),
        ];

        $vendors = Supplier::subcontractors()->orderBy('name')->pluck('name', 'id');

        return view('subcontract-orders.index', compact('orders', 'summary', 'vendors'));
    }

    public function create(Request $request)
    {
        // Dibuka dari menu Maklon tanpa SPK: tampilkan daftar SPK yang bisa dimaklonkan dulu
        if (! $request->filled('production_order_id')) {
            $spks = ProductionOrder::with(['product', 'salesOrder.customer', 'stages'])
                ->whereIn('status', [ProductionOrderStatus::Draft, ProductionOrderStatus::InProgress])
                ->orderByRaw('case when status = ? then 0 else 1 end', [ProductionOrderStatus::InProgress->value])
                ->latest('start_date')->latest('id')
                ->get();

            return view('subcontract-orders.pick-spk', compact('spks'));
        }

        $spk = ProductionOrder::with(['product', 'materials.material.unit', 'stages.subcontractOrder.supplier'])
            ->findOrFail($request->integer('production_order_id'));

        if (! in_array($spk->status, [ProductionOrderStatus::Draft, ProductionOrderStatus::InProgress], true)) {
            return redirect()->route('production-orders.show', $spk)->with('error', 'Order maklon hanya untuk SPK Draft atau Dalam Proses.');
        }

        $vendors = Supplier::subcontractors()->where('is_active', true)->orderBy('name')->get();
        $availableStages = $spk->stages->filter(fn ($s) => ! $s->subcontract_order_id && $s->status->value !== 'done');

        return view('subcontract-orders.create', [
            'spk' => $spk,
            'vendors' => $vendors,
            'availableStages' => $availableStages,
            'suggested' => SubcontractService::suggestedMaterials($spk, (float) $spk->quantity),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'production_order_id' => ['required', 'exists:production_orders,id'],
            'supplier_id' => ['required', 'exists:suppliers,id'],
            'order_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:order_date'],
            'stages' => ['required', 'array', 'min:1'],
            'stages.*' => [Rule::enum(ProductionStage::class)],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'rate' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'materials' => ['nullable', 'array'],
            'materials.*' => ['nullable', 'numeric', 'min:0'],
        ], [], ['supplier_id' => 'vendor maklon', 'stages' => 'tahap maklon', 'rate' => 'ongkos jasa', 'quantity' => 'jumlah maklon']);

        $spk = ProductionOrder::with('materials')->findOrFail($data['production_order_id']);
        $order = $this->service->create($spk, $data, $data['materials'] ?? []);

        return redirect()->route('subcontract-orders.show', $order)->with('success', "Order maklon {$order->number} berhasil dibuat.");
    }

    public function show(SubcontractOrder $subcontractOrder)
    {
        $subcontractOrder->load([
            'supplier', 'productionOrder.product', 'productionOrder.materials', 'materials.material.unit',
            'transfers.items', 'receipts.bill', 'bills', 'productionStages', 'creator', 'activities.user',
        ]);

        return view('subcontract-orders.show', ['order' => $subcontractOrder]);
    }

    public function transferForm(SubcontractOrder $subcontractOrder, string $type)
    {
        $type = SubcontractTransferType::from($type);
        $subcontractOrder->load(['supplier', 'productionOrder', 'materials.material']);

        if (! $subcontractOrder->isOpen()) {
            return redirect()->route('subcontract-orders.show', $subcontractOrder)->with('error', 'Order maklon sudah tidak berjalan.');
        }

        return view('subcontract-orders.transfer', ['order' => $subcontractOrder, 'type' => $type]);
    }

    public function transfer(Request $request, SubcontractOrder $subcontractOrder, string $type)
    {
        $type = SubcontractTransferType::from($type);
        $data = $request->validate([
            'date' => ['required', 'date'],
            'warehouse_id' => ['nullable', Warehouse::rule()],
            'vehicle_number' => ['nullable', 'string', 'max:30'],
            'driver_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array'],
            'items.*' => ['nullable', 'numeric', 'min:0'],
        ], [], ['date' => 'tanggal', 'items' => 'jumlah bahan']);

        $transfer = $type === SubcontractTransferType::Dispatch
            ? $this->service->dispatch($subcontractOrder, $data['items'], $data)
            : $this->service->returnMaterials($subcontractOrder, $data['items'], $data);

        return redirect()->route('subcontract-orders.show', $subcontractOrder)
            ->with('success', "{$type->label()} {$transfer->number} berhasil dicatat.");
    }

    public function receiveForm(SubcontractOrder $subcontractOrder)
    {
        $subcontractOrder->load(['supplier', 'productionOrder']);

        if (! $subcontractOrder->isOpen()) {
            return redirect()->route('subcontract-orders.show', $subcontractOrder)->with('error', 'Order maklon sudah tidak berjalan.');
        }

        return view('subcontract-orders.receive', [
            'order' => $subcontractOrder,
            'pendingStages' => SubcontractService::pendingPriorStages($subcontractOrder),
        ]);
    }

    public function receive(Request $request, SubcontractOrder $subcontractOrder)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'vendor_reference' => ['nullable', 'string', 'max:50'],
            'good_quantity' => ['required', 'numeric', 'min:0'],
            'reject_quantity' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ], [], ['good_quantity' => 'hasil bagus', 'reject_quantity' => 'reject', 'vendor_reference' => 'no. surat jalan vendor']);

        $receipt = $this->service->receive($subcontractOrder, $data);

        return redirect()->route('subcontract-orders.show', $subcontractOrder)->with('success', "Penerimaan hasil {$receipt->number} dicatat.");
    }

    public function close(Request $request, SubcontractOrder $subcontractOrder)
    {
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);
        $this->service->close($subcontractOrder, $data['notes'] ?? null);

        return back()->with('success', 'Order maklon ditutup dan saldo bahan direkonsiliasi.');
    }

    public function cancel(Request $request, SubcontractOrder $subcontractOrder)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $this->service->cancel($subcontractOrder, $data['reason'] ?? null);

        return back()->with('success', 'Order maklon dibatalkan.');
    }

    public function pdf(SubcontractOrder $subcontractOrder)
    {
        $subcontractOrder->load(['supplier', 'productionOrder.product', 'productionOrder.items.variant', 'productionOrder.materials', 'materials.material.unit', 'creator']);

        return DocumentPdf::stream('pdf.subcontract-order', ['order' => $subcontractOrder], $subcontractOrder->number);
    }

    public function transferPdf(SubcontractTransfer $transfer)
    {
        $transfer->load(['subcontractOrder.supplier', 'subcontractOrder.productionOrder.product', 'items.material.unit', 'creator']);

        return DocumentPdf::stream('pdf.subcontract-transfer', ['transfer' => $transfer], $transfer->number);
    }

    public function receiptPdf(SubcontractReceipt $receipt)
    {
        $receipt->load(['subcontractOrder.supplier', 'subcontractOrder.productionOrder.product', 'creator']);

        return DocumentPdf::stream('pdf.subcontract-receipt', ['receipt' => $receipt], $receipt->number);
    }
}
