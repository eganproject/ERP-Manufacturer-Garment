<?php

namespace App\Http\Controllers;

use App\Enums\StockMovementType;
use App\Models\Material;
use App\Models\ProductVariant;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StockController extends Controller
{
    public function materials(Request $request)
    {
        $items = Material::with(['unit', 'category'])
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->when($request->boolean('low_stock'), fn ($q) => $q->whereColumn('stock', '<=', 'min_stock'))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('stock.materials', compact('items'));
    }

    public function products(Request $request)
    {
        $items = ProductVariant::with('product.unit')
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('sku', 'like', "%{$s}%")
                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$s}%"))))
            ->orderBy('product_id')->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        return view('stock.products', compact('items'));
    }

    public function materialCard(Material $material)
    {
        return $this->card($material, $material->display_name, $material->unit?->name);
    }

    public function productCard(ProductVariant $variant)
    {
        $variant->load('product.unit');

        return $this->card($variant, $variant->sku.' - '.$variant->display_name, $variant->product->unit?->name);
    }

    public function adjustmentForm()
    {
        return view('stock.adjustment', [
            'materials' => Material::with('unit')->orderBy('name')->get(),
            'variants' => ProductVariant::with('product')->get()->sortBy('display_name')->values(),
        ]);
    }

    public function adjust(Request $request, StockService $stock)
    {
        $data = $request->validate([
            'item' => ['required', 'string', 'regex:/^(material|variant):\d+$/'],
            'date' => ['required', 'date'],
            'mode' => ['required', 'in:in,out,set'],
            'quantity' => ['required', 'numeric', 'min:0'],
            'notes' => ['required', 'string', 'max:255'],
        ], [
            'notes.required' => 'Alasan penyesuaian wajib diisi.',
        ]);

        [$type, $id] = explode(':', $data['item']);
        $item = $type === 'material' ? Material::findOrFail($id) : ProductVariant::findOrFail($id);

        $qty = match ($data['mode']) {
            'in' => (float) $data['quantity'],
            'out' => -1 * (float) $data['quantity'],
            'set' => (float) $data['quantity'] - (float) $item->stock,
        };

        if ($qty == 0) {
            return back()->with('error', 'Tidak ada perubahan stok.');
        }

        DB::transaction(fn () => $stock->move(
            $item, $qty, StockMovementType::Adjustment, null, null, $data['notes'], Carbon::parse($data['date']),
        ));

        return redirect()->route($type === 'material' ? 'stock.materials.card' : 'stock.products.card', $id)
            ->with('success', 'Penyesuaian stok tersimpan.');
    }

    private function card(Material|ProductVariant $item, string $title, ?string $unit)
    {
        $movements = $item->stockMovements()->with('user')->latest('date')->latest('id')->paginate(25);

        return view('stock.card', compact('item', 'title', 'unit', 'movements'));
    }
}
