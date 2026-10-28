<?php

namespace App\Http\Controllers;

use App\Models\MarketplaceStore;
use App\Models\Warehouse;
use App\Services\Marketplace\MarketplaceFulfillment;
use App\Services\ProductionPlanner;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Rencana produksi: kekurangan barang jadi dari pesanan marketplace (PO & ready stock) + SO,
 * lalu buat SPK draft per artikel sekaligus.
 */
class ProductionPlanController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:production.view'),
            new Middleware('can:production.manage', only: ['store']),
        ];
    }

    public function index(Request $request, ProductionPlanner $planner, MarketplaceFulfillment $fulfillment)
    {
        $rows = $planner->rows();
        $showAll = $request->boolean('all');

        $groups = $rows->groupBy(fn ($row) => $row['product']->id)
            ->map(fn ($variants) => [
                'product' => $variants->first()['product'],
                'variants' => $variants,
                'net' => $variants->sum('net'),
                'ship_by' => $variants->where('net', '>', 0)->pluck('ship_by')->filter()->min() ?? $variants->pluck('ship_by')->filter()->min(),
            ])
            ->filter(fn ($group) => $showAll || $group['net'] > 0)
            ->sortBy(fn ($group) => [$group['net'] > 0 ? 0 : 1, $group['ship_by']?->timestamp ?? PHP_INT_MAX, $group['product']->name])
            ->values();

        // SPK untuk marketplace sebaiknya masuk ke gudang toko agar langsung teralokasi ke pesanan
        $storeWarehouse = MarketplaceStore::active()->selectRaw('warehouse_id, COUNT(*) as n')->groupBy('warehouse_id')->orderByDesc('n')->value('warehouse_id');

        return view('production-plan.index', [
            'groups' => $groups,
            'showAll' => $showAll,
            'waiting' => count($fulfillment->idsInState(MarketplaceFulfillment::WAITING)),
            'ready' => count($fulfillment->idsInState(MarketplaceFulfillment::READY)),
            'urgent' => $fulfillment->urgentWaitingCount(),
            'warehouseId' => $storeWarehouse ?: Warehouse::default()->id,
        ]);
    }

    public function store(Request $request, ProductionPlanner $planner)
    {
        $data = $request->validate([
            'selected' => ['required', 'array', 'min:1'],
            'selected.*' => ['integer'],
            'products' => ['required', 'array'],
            'products.*' => ['array'],
            'products.*.*' => ['nullable', 'numeric', 'min:0'],
            'due' => ['nullable', 'array'],
            'due.*' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', Warehouse::rule()],
        ], ['selected.required' => 'Centang minimal satu artikel yang akan dibuatkan SPK.'], ['warehouse_id' => 'gudang']);

        $selected = array_map('intval', $data['selected']);
        $products = array_intersect_key($data['products'], array_flip($selected));
        $orders = $planner->createOrders($products, $data['due'] ?? [], $data['warehouse_id'] ?? null);

        if ($orders->count() === 1) {
            return redirect()->route('production-orders.show', $orders->first())->with('success', "SPK {$orders->first()->number} dibuat (draft). Periksa lalu klik Mulai Produksi.");
        }

        return redirect()->route('production-orders.index', ['status' => 'draft'])
            ->with('success', $orders->count().' SPK draft dibuat: '.$orders->pluck('number')->implode(', ').'. Periksa lalu mulai produksinya.');
    }
}
