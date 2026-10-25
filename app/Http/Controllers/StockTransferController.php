<?php

namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\ProductVariant;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Services\WarehouseStockService;
use App\Support\DocumentPdf;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Transfer stok antar gudang (TG-).
 */
class StockTransferController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:stock.view', only: ['index', 'show', 'pdf']),
            new Middleware('can:stock.transfer', only: ['create', 'store', 'cancel']),
        ];
    }

    public function index(Request $request)
    {
        $transfers = StockTransfer::with(['fromWarehouse', 'toWarehouse', 'items'])
            ->when($request->q, fn ($q, $s) => $q->where('number', 'like', "%{$s}%"))
            ->when($request->warehouse_id, fn ($q, $id) => $q->where(fn ($w) => $w->where('from_warehouse_id', $id)->orWhere('to_warehouse_id', $id)))
            ->latest('date')->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('stock-transfers.index', ['transfers' => $transfers, 'warehouses' => Warehouse::orderBy('name')->pluck('name', 'id')]);
    }

    public function create(Request $request)
    {
        $from = $request->integer('from_warehouse_id') ?: Warehouse::default()->id;

        return view('stock-transfers.create', [
            'from' => $from,
            'materials' => Material::with(['unit', 'warehouseStocks'])->where('is_active', true)->orderBy('name')->get(),
            'variants' => ProductVariant::with(['product', 'warehouseStocks'])->get()->sortBy('display_name')->values(),
        ]);
    }

    public function store(Request $request, WarehouseStockService $service)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'from_warehouse_id' => ['required', Warehouse::rule()],
            'to_warehouse_id' => ['required', Warehouse::rule()],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item' => ['nullable', 'string'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
        ], [], ['from_warehouse_id' => 'gudang asal', 'to_warehouse_id' => 'gudang tujuan', 'lines' => 'barang']);

        $transfer = $service->transfer($data, $data['lines']);

        return redirect()->route('stock-transfers.show', $transfer)->with('success', "Transfer {$transfer->number} tersimpan.");
    }

    public function show(StockTransfer $stockTransfer)
    {
        $stockTransfer->load(['fromWarehouse', 'toWarehouse', 'items.stockable', 'creator', 'activities.user']);

        return view('stock-transfers.show', ['transfer' => $stockTransfer]);
    }

    public function cancel(Request $request, StockTransfer $stockTransfer, WarehouseStockService $service)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'alasan pembatalan']);
        $service->cancelTransfer($stockTransfer, $data['reason']);

        return back()->with('success', 'Transfer dibatalkan, barang kembali ke gudang asal.');
    }

    public function pdf(StockTransfer $stockTransfer)
    {
        $stockTransfer->load(['fromWarehouse', 'toWarehouse', 'items.stockable', 'creator']);

        return DocumentPdf::stream('pdf.stock-transfer', ['transfer' => $stockTransfer], $stockTransfer->number);
    }
}
