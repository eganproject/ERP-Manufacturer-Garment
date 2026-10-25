<?php

namespace App\Http\Controllers\Payroll;

use App\Enums\ProductionStage;
use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Tarif borongan per artikel: daftar operasi/segmen (tarif per pcs) dan tarif pcs jadi (komplit).
 */
class PieceRateController extends Controller
{
    public function index(Request $request)
    {
        $products = Product::withCount(['operations' => fn ($q) => $q->where('is_active', true)])
            ->withSum(['operations as operations_rate' => fn ($q) => $q->where('is_active', true)], 'rate')
            ->with('activeBom')
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%")))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('payroll.piece-rates.index', compact('products'));
    }

    public function edit(Product $product)
    {
        $product->load(['operations' => fn ($q) => $q->withCount('entries'), 'activeBom']);

        return view('payroll.piece-rates.edit', ['product' => $product, 'stages' => collect(ProductionStage::cases())->mapWithKeys(fn ($s) => [$s->value => $s->label()])]);
    }

    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'full_garment_rate' => ['nullable', 'numeric', 'min:0'],
            'operations' => ['nullable', 'array'],
            'operations.*.id' => ['nullable', 'integer'],
            'operations.*.name' => ['nullable', 'string', 'max:255'],
            'operations.*.stage' => ['nullable', Rule::enum(ProductionStage::class)],
            'operations.*.rate' => ['nullable', 'numeric', 'min:0'],
            'operations.*.is_active' => ['nullable', 'boolean'],
        ], [], ['full_garment_rate' => 'tarif pcs jadi', 'operations.*.name' => 'nama operasi', 'operations.*.rate' => 'tarif operasi']);

        DB::transaction(function () use ($product, $data) {
            $existing = $product->operations()->withCount('entries')->get()->keyBy('id');
            $kept = [];
            $sequence = 0;

            foreach ($data['operations'] ?? [] as $row) {
                $name = trim((string) ($row['name'] ?? ''));

                if ($name === '') {
                    continue;
                }

                $values = [
                    'name' => $name,
                    'stage' => $row['stage'] ?? ProductionStage::Sewing->value,
                    'rate' => (float) ($row['rate'] ?? 0),
                    'sequence' => ++$sequence,
                    'is_active' => (bool) ($row['is_active'] ?? true),
                ];

                if (! empty($row['id']) && $existing->has((int) $row['id'])) {
                    $existing[(int) $row['id']]->update($values);
                    $kept[] = (int) $row['id'];
                } else {
                    $kept[] = $product->operations()->create($values)->id;
                }
            }

            foreach ($existing->except($kept) as $operation) {
                if ($operation->entries_count > 0) {
                    throw ValidationException::withMessages(['operations' => "Operasi \"{$operation->name}\" sudah dipakai di hasil kerja; nonaktifkan saja, jangan dihapus."]);
                }

                $operation->delete();
            }

            $product->update(['full_garment_rate' => (float) ($data['full_garment_rate'] ?? 0)]);
        });

        ActivityLog::record('piece-rates', "Mengubah tarif borongan {$product->code} - {$product->name}");

        return redirect()->route('piece-rates.edit', $product)->with('success', 'Tarif borongan disimpan. Hasil kerja yang sudah dicatat tetap memakai tarif lama.');
    }
}
