<?php

namespace App\Http\Controllers;

use App\Enums\StockOpnameStatus;
use App\Models\Category;
use App\Models\StockOpname;
use App\Models\Warehouse;
use App\Services\WarehouseStockService;
use App\Support\DocumentPdf;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Stock opname per gudang (OPN-): lembar hitung → isi hasil fisik → posting selisih.
 */
class StockOpnameController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:stock.view', only: ['index', 'show', 'pdf']),
            new Middleware('can:stock.adjust', only: ['create', 'store', 'update', 'post', 'cancel']),
        ];
    }

    public function index(Request $request)
    {
        $opnames = StockOpname::with(['warehouse', 'creator'])->withCount('items')
            ->withSum('items as total_value', 'value')
            ->when($request->warehouse_id, fn ($q, $id) => $q->where('warehouse_id', $id))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest('date')->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('stock-opnames.index', ['opnames' => $opnames, 'warehouses' => Warehouse::orderBy('name')->pluck('name', 'id')]);
    }

    public function create()
    {
        return view('stock-opnames.create', [
            'categories' => [
                'materials' => Category::ofType('material')->orderBy('name')->pluck('name', 'id'),
                'products' => Category::ofType('product')->orderBy('name')->pluck('name', 'id'),
            ],
        ]);
    }

    public function store(Request $request, WarehouseStockService $service)
    {
        $data = $request->validate([
            'warehouse_id' => ['required', Warehouse::rule()],
            'date' => ['required', 'date'],
            'scope' => ['required', Rule::in(array_keys(StockOpname::SCOPES))],
            'category_id' => ['nullable', 'integer'],
            'only_in_stock' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string'],
        ], [], ['warehouse_id' => 'gudang', 'scope' => 'jenis barang']);
        $data['only_in_stock'] = $request->boolean('only_in_stock');

        $opname = $service->createOpname($data);

        return redirect()->route('stock-opnames.show', $opname)->with('success', "Lembar stock opname {$opname->number} dibuat. Cetak lembar hitung, lalu isi hasil hitung fisik.");
    }

    public function show(StockOpname $stockOpname)
    {
        $stockOpname->load(['warehouse', 'items.stockable', 'creator', 'poster', 'activities.user']);
        $stockOpname->items->each(fn ($i) => $i->stockable?->loadMissing($i->stockable_type === 'material' ? 'unit' : 'product'));

        return view('stock-opnames.show', ['opname' => $stockOpname]);
    }

    public function update(Request $request, StockOpname $stockOpname, WarehouseStockService $service)
    {
        $data = $request->validate([
            'counts' => ['nullable', 'array'],
            'counts.*' => ['nullable', 'numeric', 'min:0'],
            'item_notes' => ['nullable', 'array'],
            'item_notes.*' => ['nullable', 'string', 'max:255'],
        ], [], ['counts.*' => 'jumlah fisik']);

        $service->saveCounts($stockOpname, $data['counts'] ?? [], $data['item_notes'] ?? []);

        if ($request->boolean('post')) {
            $service->postOpname($stockOpname->fresh());

            return redirect()->route('stock-opnames.show', $stockOpname)->with('success', 'Stock opname diposting, selisih stok disesuaikan.');
        }

        return back()->with('success', 'Hasil hitung disimpan.');
    }

    public function cancel(Request $request, StockOpname $stockOpname, WarehouseStockService $service)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $service->cancelOpname($stockOpname, $data['reason'] ?? null);

        return back()->with('success', 'Stock opname dibatalkan.');
    }

    public function pdf(StockOpname $stockOpname)
    {
        $stockOpname->load(['warehouse', 'items.stockable', 'creator']);
        $stockOpname->items->each(fn ($i) => $i->stockable?->loadMissing($i->stockable_type === 'material' ? 'unit' : 'product.unit'));

        return DocumentPdf::stream('pdf.stock-opname', ['opname' => $stockOpname, 'blind' => $stockOpname->status === StockOpnameStatus::Draft], $stockOpname->number);
    }
}
