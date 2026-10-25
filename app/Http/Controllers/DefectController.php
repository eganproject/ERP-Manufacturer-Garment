<?php

namespace App\Http\Controllers;

use App\Enums\DefectHandlingType;
use App\Models\Account;
use App\Models\DefectHandling;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\DefectService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/**
 * Stok barang cacat (karantina) dan penanganannya: perbaiki, kembalikan ke stok jual, musnahkan.
 */
class DefectController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:stock.view', only: ['index', 'show']),
            new Middleware('can:defects.manage', only: ['create', 'store', 'cancel']),
        ];
    }

    public function index(Request $request)
    {
        $request->validate(['type' => ['nullable', Rule::enum(DefectHandlingType::class)]]);

        $stock = ProductVariant::with('product.unit')->where('defect_stock', '>', 0)
            ->get()->sortBy('display_name')->values();

        $handlings = DefectHandling::with('items.variant.product')
            ->when($request->type, fn ($q, $t) => $q->where('type', $t))
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")->orWhere('notes', 'like', "%{$s}%")))
            ->latest('date')->latest('id')
            ->paginate(15)->withQueryString();

        return view('defects.index', [
            'stock' => $stock,
            'handlings' => $handlings,
            'totalQty' => round($stock->sum(fn ($v) => (float) $v->defect_stock), 2),
            'totalValue' => round($stock->sum(fn ($v) => (float) $v->defect_stock * (float) $v->defect_cost), 2),
        ]);
    }

    public function create(Request $request)
    {
        $type = DefectHandlingType::tryFrom((string) $request->query('type')) ?? DefectHandlingType::Rework;
        $column = $type->fromDefect() ? 'defect_stock' : 'stock';

        $variants = ProductVariant::with('product')->where($column, '>', 0)->get()->sortBy('display_name')
            ->mapWithKeys(fn ($v) => [$v->id => "{$v->sku} – {$v->display_name} (".($type->fromDefect() ? 'cacat ' : 'stok ').qty($v->{$column}).')']);

        return view('defects.create', [
            'type' => $type,
            'variants' => $variants,
            'cashAccounts' => Account::cashOptions(),
            'initial' => $request->integer('variant_id') ? [['product_variant_id' => $request->integer('variant_id'), 'quantity' => '']] : null,
        ]);
    }

    public function store(Request $request, DefectService $service)
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(DefectHandlingType::class)],
            'date' => ['required', 'date'],
            'warehouse_id' => ['nullable', Warehouse::rule()],
            'notes' => ['nullable', 'string', 'max:255'],
            'extra_cost' => ['nullable', 'numeric', 'min:0'],
            'cash_account_id' => ['nullable', Account::cashRule()],
            'lines' => ['required', 'array'],
            'lines.*.product_variant_id' => ['nullable', 'integer'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
        ], [], ['extra_cost' => 'biaya perbaikan', 'cash_account_id' => 'rekening kas/bank', 'lines' => 'barang']);

        $handling = $service->record(DefectHandlingType::from($data['type']), $data, $data['lines']);

        return redirect()->route('defects.show', $handling)->with('success', "{$handling->type->label()} {$handling->number} tersimpan.");
    }

    public function show(DefectHandling $defect)
    {
        $defect->load(['items.variant.product.unit', 'cashAccount', 'creator', 'activities.user']);

        return view('defects.show', ['handling' => $defect]);
    }

    public function cancel(Request $request, DefectHandling $defect, DefectService $service)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'alasan pembatalan']);
        $service->cancel($defect, $data['reason']);

        return back()->with('success', "{$defect->number} dibatalkan, stok dikembalikan dan jurnalnya dibalik.");
    }
}
