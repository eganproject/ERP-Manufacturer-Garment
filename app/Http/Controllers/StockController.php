<?php

namespace App\Http\Controllers;

use App\Enums\StockMovementType;
use App\Models\Material;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\Accounting\AutoJournal;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockController extends Controller
{
    public function materials(Request $request)
    {
        $items = Material::with(['unit', 'category', 'warehouseStocks'])
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->when($request->boolean('low_stock'), fn ($q) => $q->whereColumn('stock', '<=', 'min_stock'))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('stock.materials', ['items' => $items] + $this->warehouseFilter($request));
    }

    public function products(Request $request)
    {
        $items = ProductVariant::with(['product.unit', 'warehouseStocks'])
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('sku', 'like', "%{$s}%")
                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$s}%"))))
            ->orderBy('product_id')->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return view('stock.products', ['items' => $items] + $this->warehouseFilter($request));
    }

    public function materialCard(Request $request, Material $material)
    {
        return $this->card($request, $material, $material->display_name, $material->unit?->name);
    }

    public function productCard(Request $request, ProductVariant $variant)
    {
        $variant->load('product.unit');

        return $this->card($request, $variant, $variant->sku.' - '.$variant->display_name, $variant->product->unit?->name);
    }

    public function adjustmentForm()
    {
        return view('stock.adjustment', [
            'materials' => Material::with('unit')->orderBy('name')->get(),
            'variants' => ProductVariant::with('product')->get()->sortBy('display_name')->values(),
        ]);
    }

    public function adjust(Request $request, StockService $stock, AutoJournal $journal)
    {
        $data = $request->validate([
            'item' => ['required', 'string', 'regex:/^(material|variant):\d+$/'],
            'date' => ['required', 'date'],
            'warehouse_id' => ['nullable', Warehouse::rule()],
            'mode' => ['required', 'in:in,out,set'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'notes' => ['required', 'string', 'max:255'],
        ], [
            'notes.required' => 'Alasan penyesuaian wajib diisi.',
        ]);

        [$type, $id] = explode(':', $data['item']);
        $item = $type === 'material' ? Material::findOrFail($id) : ProductVariant::findOrFail($id);
        $warehouseId = Warehouse::resolveId($data['warehouse_id'] ?? null);

        $qty = match ($data['mode']) {
            'in' => (float) $data['quantity'],
            'out' => -1 * (float) $data['quantity'],
            'set' => (float) $data['quantity'] - $item->stockIn($warehouseId),
        };

        if ($qty == 0) {
            return back()->with('error', 'Tidak ada perubahan stok.');
        }

        DB::transaction(fn () => $journal->stockAdjustment($stock->move(
            $item, $qty, StockMovementType::Adjustment, null, null, $data['notes'], Carbon::parse($data['date']), warehouse: $warehouseId,
        )));

        return redirect()->route($type === 'material' ? 'stock.materials.card' : 'stock.products.card', $id)
            ->with('success', 'Penyesuaian stok tersimpan.');
    }

    /**
     * Filter gudang di daftar stok: kosong = total semua gudang (dengan rincian per gudang).
     *
     * @return array{warehouses: Collection<int, Warehouse>, warehouse: ?Warehouse}
     */
    private function warehouseFilter(Request $request): array
    {
        $warehouses = Warehouse::orderByDesc('is_default')->orderBy('name')->get()->keyBy('id');

        return ['warehouses' => $warehouses, 'warehouse' => $request->warehouse_id ? $warehouses->get($request->warehouse_id) : null];
    }

    private function card(Request $request, Material|ProductVariant $item, string $title, ?string $unit)
    {
        $filter = $this->warehouseFilter($request);
        $item->load('warehouseStocks');

        $movements = $item->stockMovements()->with(['user', 'warehouse'])
            ->when($filter['warehouse'], fn ($q, $w) => $q->where('warehouse_id', $w->id))
            ->latest('date')->latest('id')
            ->paginate(25)
            ->withQueryString();

        return view('stock.card', compact('item', 'title', 'unit', 'movements') + $filter);
    }
}
