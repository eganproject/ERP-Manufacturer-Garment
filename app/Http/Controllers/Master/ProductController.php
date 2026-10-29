<?php

namespace App\Http\Controllers\Master;

use App\Enums\ProductionStage;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Unit;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::with(['category', 'unit'])
            ->withCount('variants')
            ->withSum('variants', 'stock')
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->when($request->category_id, fn ($q, $c) => $q->where('category_id', $c))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $categories = Category::ofType('product')->orderBy('name')->get();

        return view('master.products.index', compact('products', 'categories'));
    }

    public function create()
    {
        return view('master.products.form', $this->formData(new Product(['is_active' => true])));
    }

    public function store(Request $request)
    {
        [$data, $variants] = $this->validated($request);

        DB::transaction(function () use ($data, $variants) {
            $product = Product::create($data);
            $this->syncVariants($product, $variants);
        });

        return redirect()->route('products.index')->with('success', 'Produk berhasil ditambahkan.');
    }

    public function show(Product $product)
    {
        $product->load(['category', 'unit', 'variants', 'boms.items.material.unit']);

        return view('master.products.show', compact('product'));
    }

    public function edit(Product $product)
    {
        $product->load('variants');

        return view('master.products.form', $this->formData($product));
    }

    public function update(Request $request, Product $product)
    {
        [$data, $variants] = $this->validated($request, $product);

        DB::transaction(function () use ($product, $data, $variants) {
            $product->update($data);
            $this->syncVariants($product, $variants);
        });

        return redirect()->route('products.index')->with('success', 'Produk berhasil diperbarui.');
    }

    public function destroy(Product $product)
    {
        try {
            $product->delete();
        } catch (QueryException) {
            return back()->with('error', 'Produk sudah dipakai di transaksi, nonaktifkan saja.');
        }

        return back()->with('success', 'Produk berhasil dihapus.');
    }

    private function formData(Product $product): array
    {
        return [
            'product' => $product,
            'categories' => Category::ofType('product')->orderBy('name')->get(),
            'units' => Unit::orderBy('name')->get(),
            'sizes' => ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL', 'All Size'],
        ];
    }

    private function validated(Request $request, ?Product $product = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('products')->ignore($product)],
            'name' => ['required', 'string', 'max:255'],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('type', 'product')],
            'unit_id' => ['required', 'exists:units,id'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string'],
            'stage_route' => ['nullable', 'array'],
            'stage_route.*' => [Rule::enum(ProductionStage::class)],
            'variants' => ['required', 'array', 'min:1'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.sku' => ['required', 'string', 'max:50', 'distinct'],
            'variants.*.size' => ['required', 'string', 'max:20'],
            'variants.*.color' => ['nullable', 'string', 'max:50'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0'],
        ], [
            'variants.required' => 'Tambahkan minimal satu varian (ukuran/warna).',
            'variants.*.sku.distinct' => 'SKU varian tidak boleh sama.',
        ]);

        $variants = $data['variants'];
        unset($data['variants']);

        return [array_merge($data, [
            'price' => $data['price'] ?? 0,
            'is_active' => $request->boolean('is_active'),
            // Simpan null bila sama dengan tahap standar agar artikel lama & baru konsisten
            'stage_route' => in_array($route = array_map(fn ($s) => $s->value, ProductionStage::ordered($data['stage_route'] ?? [])), [[], array_map(fn ($s) => $s->value, ProductionStage::standard())], true) ? null : $route,
        ]), $variants];
    }

    private function syncVariants(Product $product, array $variants): void
    {
        $keepIds = [];

        foreach ($variants as $row) {
            $skuTaken = ProductVariant::where('sku', $row['sku'])
                ->when(! empty($row['id']), fn ($q) => $q->where('id', '!=', $row['id']))
                ->exists();

            if ($skuTaken) {
                throw ValidationException::withMessages(['variants' => "SKU {$row['sku']} sudah dipakai."]);
            }

            $attributes = [
                'sku' => $row['sku'],
                'size' => $row['size'],
                'color' => $row['color'] ?? null,
                'price' => ($row['price'] ?? '') === '' ? null : $row['price'],
            ];

            $variant = ! empty($row['id'])
                ? tap($product->variants()->findOrFail($row['id']))->update($attributes)
                : $product->variants()->create($attributes);

            $keepIds[] = $variant->id;
        }

        $removed = $product->variants()->whereNotIn('id', $keepIds)->get();

        foreach ($removed as $variant) {
            try {
                if ((float) $variant->stock != 0 || $variant->stockMovements()->exists()) {
                    throw ValidationException::withMessages(['variants' => "Varian {$variant->sku} sudah memiliki stok/mutasi dan tidak dapat dihapus."]);
                }
                $variant->delete();
            } catch (QueryException) {
                throw ValidationException::withMessages(['variants' => "Varian {$variant->sku} sudah dipakai di transaksi dan tidak dapat dihapus."]);
            }
        }
    }
}
