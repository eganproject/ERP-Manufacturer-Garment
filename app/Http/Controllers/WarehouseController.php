<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Material;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Master gudang / lokasi penyimpanan beserta ringkasan stok per gudang.
 */
class WarehouseController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:stock.view', only: ['index', 'show']),
            new Middleware('can:warehouses.manage', except: ['index', 'show']),
        ];
    }

    public function index()
    {
        $warehouses = Warehouse::orderByDesc('is_default')->orderBy('name')->get();
        $summary = WarehouseStock::query()
            ->selectRaw('warehouse_id, stockable_type, COUNT(CASE WHEN quantity <> 0 THEN 1 END) as items, SUM(quantity) as qty, SUM(defect_quantity) as defect')
            ->groupBy('warehouse_id', 'stockable_type')
            ->get()
            ->groupBy('warehouse_id');

        return view('warehouses.index', compact('warehouses', 'summary') + ['values' => $this->values()]);
    }

    public function show(Request $request, Warehouse $warehouse)
    {
        $type = $request->input('type', 'materials') === 'products' ? 'products' : 'materials';
        $rows = WarehouseStock::with($type === 'materials' ? 'stockable.unit' : 'stockable.product')
            ->where('warehouse_id', $warehouse->id)
            ->where('stockable_type', $type === 'materials' ? 'material' : 'product_variant')
            ->where(fn ($q) => $q->where('quantity', '!=', 0)->orWhere('defect_quantity', '!=', 0))
            ->get()
            ->sortBy(fn ($r) => $type === 'materials' ? $r->stockable->name : $r->stockable->sku)
            ->values();

        return view('warehouses.show', compact('warehouse', 'type', 'rows') + ['value' => $this->values()[$warehouse->id] ?? 0]);
    }

    public function create()
    {
        return view('warehouses.form', ['warehouse' => new Warehouse(['is_active' => true])]);
    }

    public function store(Request $request)
    {
        $warehouse = DB::transaction(function () use ($request) {
            $data = $this->validated($request);
            $warehouse = Warehouse::create($data);
            $this->syncDefault($warehouse);
            ActivityLog::record('created', "Menambah gudang {$warehouse->display_name}", $warehouse);

            return $warehouse;
        });

        return redirect()->route('warehouses.index')->with('success', "Gudang {$warehouse->name} ditambahkan.");
    }

    public function edit(Warehouse $warehouse)
    {
        return view('warehouses.form', compact('warehouse'));
    }

    public function update(Request $request, Warehouse $warehouse)
    {
        $data = $this->validated($request, $warehouse);

        if (! $data['is_active'] && $warehouse->is_active && WarehouseStock::where('warehouse_id', $warehouse->id)
            ->where(fn ($q) => $q->where('quantity', '!=', 0)->orWhere('defect_quantity', '!=', 0))->exists()) {
            return back()->withInput()->with('error', 'Gudang masih berisi stok. Pindahkan stoknya (transfer antar gudang) sebelum dinonaktifkan.');
        }

        if ($warehouse->is_default && (! $data['is_default'] || ! $data['is_active'])) {
            return back()->withInput()->with('error', 'Gudang default tidak bisa dinonaktifkan / dilepas. Jadikan gudang lain sebagai default terlebih dulu.');
        }

        DB::transaction(function () use ($warehouse, $data) {
            $warehouse->update($data);
            $this->syncDefault($warehouse);
            ActivityLog::record('updated', 'Mengubah data gudang', $warehouse);
        });

        return redirect()->route('warehouses.index')->with('success', 'Gudang diperbarui.');
    }

    public function destroy(Warehouse $warehouse)
    {
        if ($warehouse->is_default || StockMovement::where('warehouse_id', $warehouse->id)->exists()) {
            return back()->with('error', 'Gudang default atau yang sudah punya mutasi stok tidak bisa dihapus; nonaktifkan saja.');
        }

        WarehouseStock::where('warehouse_id', $warehouse->id)->delete();
        $warehouse->delete();

        return back()->with('success', 'Gudang dihapus.');
    }

    /** Nilai stok per gudang (qty × harga pokok rata-rata barang). */
    private function values(): array
    {
        $materials = Material::all()->mapWithKeys(fn ($m) => [$m->id => $m->costPrice()]);
        $variants = ProductVariant::all()->mapWithKeys(fn ($v) => [$v->id => [(float) $v->average_cost, (float) $v->defect_cost]]);

        return WarehouseStock::all()->groupBy('warehouse_id')->map(fn ($rows) => round($rows->sum(function ($r) use ($materials, $variants) {
            if ($r->stockable_type === 'material') {
                return (float) $r->quantity * ($materials[$r->stockable_id] ?? 0);
            }
            [$cost, $defectCost] = $variants[$r->stockable_id] ?? [0, 0];

            return (float) $r->quantity * $cost + (float) $r->defect_quantity * $defectCost;
        }), 2))->all();
    }

    private function syncDefault(Warehouse $warehouse): void
    {
        if ($warehouse->is_default) {
            Warehouse::whereKeyNot($warehouse->id)->update(['is_default' => false]);
        }
    }

    private function validated(Request $request, ?Warehouse $warehouse = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('warehouses')->ignore($warehouse)],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
        ], [], ['code' => 'kode', 'name' => 'nama gudang']);

        $data['is_active'] = $request->boolean('is_active');
        $data['is_default'] = $request->boolean('is_default') && $data['is_active'];
        $data['code'] = strtoupper($data['code']);

        return $data;
    }
}
