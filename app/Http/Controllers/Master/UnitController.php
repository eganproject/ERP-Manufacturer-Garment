<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Unit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    public function index(Request $request)
    {
        $units = Unit::query()
            ->when($request->q, fn ($q, $s) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('master.units.index', compact('units'));
    }

    public function create()
    {
        return view('master.units.form', ['unit' => new Unit]);
    }

    public function store(Request $request)
    {
        Unit::create($this->validated($request));

        return redirect()->route('units.index')->with('success', 'Satuan berhasil ditambahkan.');
    }

    public function edit(Unit $unit)
    {
        return view('master.units.form', compact('unit'));
    }

    public function update(Request $request, Unit $unit)
    {
        $unit->update($this->validated($request, $unit));

        return redirect()->route('units.index')->with('success', 'Satuan berhasil diperbarui.');
    }

    public function destroy(Unit $unit)
    {
        if ($unit->materials()->exists() || $unit->products()->exists()) {
            return back()->with('error', 'Satuan masih dipakai oleh bahan/produk.');
        }

        $unit->delete();

        return back()->with('success', 'Satuan berhasil dihapus.');
    }

    private function validated(Request $request, ?Unit $unit = null): array
    {
        return $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('units')->ignore($unit)],
            'name' => ['required', 'string', 'max:100'],
        ]);
    }
}
