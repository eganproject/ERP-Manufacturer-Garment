<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Material;
use App\Models\Supplier;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MaterialController extends Controller
{
    public function index(Request $request)
    {
        $materials = Material::with(['category', 'unit'])
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->when($request->category_id, fn ($q, $c) => $q->where('category_id', $c))
            ->when($request->boolean('low_stock'), fn ($q) => $q->whereColumn('stock', '<=', 'min_stock'))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $categories = Category::ofType('material')->orderBy('name')->get();

        return view('master.materials.index', compact('materials', 'categories'));
    }

    public function create()
    {
        return view('master.materials.form', $this->formData(new Material(['is_active' => true])));
    }

    public function store(Request $request)
    {
        Material::create($this->validated($request));

        return redirect()->route('materials.index')->with('success', 'Bahan baku berhasil ditambahkan.');
    }

    public function edit(Material $material)
    {
        return view('master.materials.form', $this->formData($material));
    }

    public function update(Request $request, Material $material)
    {
        $material->update($this->validated($request, $material));

        return redirect()->route('materials.index')->with('success', 'Bahan baku berhasil diperbarui.');
    }

    public function destroy(Material $material)
    {
        if ($material->stockMovements()->exists()) {
            return back()->with('error', 'Bahan sudah memiliki mutasi stok, nonaktifkan saja.');
        }

        try {
            $material->delete();
        } catch (QueryException) {
            return back()->with('error', 'Bahan masih dipakai di BOM atau transaksi.');
        }

        return back()->with('success', 'Bahan baku berhasil dihapus.');
    }

    private function formData(Material $material): array
    {
        return [
            'material' => $material,
            'categories' => Category::ofType('material')->orderBy('name')->get(),
            'units' => Unit::orderBy('name')->get(),
            'suppliers' => Supplier::where('is_active', true)->orWhere('id', $material->default_supplier_id)->orderBy('name')->pluck('name', 'id'),
        ];
    }

    private function validated(Request $request, ?Material $material = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('materials')->ignore($material)],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('type', 'material')],
            'unit_id' => ['required', 'exists:units,id'],
            'default_supplier_id' => ['nullable', 'exists:suppliers,id'],
            'color' => ['nullable', 'string', 'max:50'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'min_stock' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ]);

        return array_merge($data, [
            'price' => $data['price'] ?? 0,
            'min_stock' => $data['min_stock'] ?? 0,
            'is_active' => $request->boolean('is_active'),
        ]);
    }
}
