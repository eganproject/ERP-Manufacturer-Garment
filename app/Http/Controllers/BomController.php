<?php

namespace App\Http\Controllers;

use App\Models\Bom;
use App\Models\Material;
use App\Models\Product;
use App\Support\DocumentPdf;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BomController extends Controller
{
    public function index(Request $request)
    {
        $boms = Bom::with('product')
            ->withCount('items')
            ->when($request->q, fn ($q, $s) => $q->where('name', 'like', "%{$s}%")
                ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->latest()
            ->paginate(15)
            ->withQueryString();

        return view('boms.index', compact('boms'));
    }

    public function create(Request $request)
    {
        return view('boms.form', $this->formData(new Bom([
            'is_active' => true,
            'product_id' => $request->integer('product_id') ?: null,
        ])));
    }

    public function store(Request $request)
    {
        [$data, $items] = $this->validated($request);

        $bom = DB::transaction(function () use ($data, $items) {
            $bom = Bom::create($data);
            $bom->items()->createMany($items);
            $this->deactivateOthers($bom);

            return $bom;
        });

        return redirect()->route('boms.show', $bom)->with('success', 'BOM berhasil dibuat.');
    }

    public function show(Bom $bom)
    {
        $bom->load(['product.unit', 'items.material.unit']);

        return view('boms.show', compact('bom'));
    }

    /** Lembar kebutuhan bahan (untuk bagian potong / gudang), opsional dikalikan jumlah produksi. */
    public function pdf(Request $request, Bom $bom)
    {
        $bom->load(['product.unit', 'items.material.unit']);
        $quantity = max(1, (int) $request->integer('qty', 1));

        return DocumentPdf::stream('pdf.bom', ['bom' => $bom, 'quantity' => $quantity, 'showCost' => $request->user()->can('costing.view')], 'BOM-'.$bom->product->code);
    }

    public function edit(Bom $bom)
    {
        $bom->load('items');

        return view('boms.form', $this->formData($bom));
    }

    public function update(Request $request, Bom $bom)
    {
        [$data, $items] = $this->validated($request);

        DB::transaction(function () use ($bom, $data, $items) {
            $bom->update($data);
            $bom->items()->delete();
            $bom->items()->createMany($items);
            $this->deactivateOthers($bom);
        });

        return redirect()->route('boms.show', $bom)->with('success', 'BOM berhasil diperbarui.');
    }

    public function destroy(Bom $bom)
    {
        try {
            $bom->delete();
        } catch (QueryException) {
            return back()->with('error', 'BOM sudah dipakai di SPK produksi, nonaktifkan saja.');
        }

        return redirect()->route('boms.index')->with('success', 'BOM berhasil dihapus.');
    }

    private function deactivateOthers(Bom $bom): void
    {
        if ($bom->is_active) {
            Bom::where('product_id', $bom->product_id)->where('id', '!=', $bom->id)->update(['is_active' => false]);
        }
    }

    private function formData(Bom $bom): array
    {
        return [
            'bom' => $bom,
            'products' => Product::where('is_active', true)->orderBy('name')->get(),
            'materials' => Material::with('unit')->where('is_active', true)->orderBy('name')->get(),
        ];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'name' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'labor_cost' => ['nullable', 'numeric', 'min:0'],
            'overhead_cost' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.material_id' => ['required', 'exists:materials,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.waste_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ], [
            'items.required' => 'Tambahkan minimal satu bahan.',
        ]);

        $items = array_map(fn ($row) => [
            'material_id' => $row['material_id'],
            'quantity' => $row['quantity'],
            'waste_percent' => $row['waste_percent'] ?? 0,
            'notes' => $row['notes'] ?? null,
        ], array_values($data['items']));

        unset($data['items']);

        return [array_merge($data, [
            'labor_cost' => $data['labor_cost'] ?? 0,
            'overhead_cost' => $data['overhead_cost'] ?? 0,
            'is_active' => $request->boolean('is_active'),
        ]), $items];
    }
}
