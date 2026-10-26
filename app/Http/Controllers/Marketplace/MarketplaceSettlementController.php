<?php

namespace App\Http\Controllers\Marketplace;

use App\Enums\MarketplaceSettlementStatus;
use App\Http\Controllers\Controller;
use App\Models\MarketplaceSettlement;
use App\Models\MarketplaceStore;
use App\Services\Marketplace\MarketplaceImporter;
use App\Services\Marketplace\MarketplaceSettlementService;
use App\Support\DocumentPdf;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/** Pencairan dana marketplace (PCD-): impor laporan penghasilan → periksa → posting jurnal. */
class MarketplaceSettlementController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:marketplace.view', only: ['index', 'show', 'template', 'pdf']),
            new Middleware('can:marketplace.settle', except: ['index', 'show', 'template', 'pdf']),
        ];
    }

    public function index(Request $request)
    {
        $settlements = MarketplaceSettlement::with('store')->withCount('lines')
            ->when($request->store_id, fn ($q, $id) => $q->where('marketplace_store_id', $id))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->when($request->q, fn ($q, $s) => $q->where('number', 'like', "%{$s}%"))
            ->latest('date')->latest('id')
            ->paginate(20)->withQueryString();

        return view('marketplace.settlements.index', [
            'settlements' => $settlements,
            'stores' => MarketplaceStore::options(false),
            'statuses' => MarketplaceSettlementStatus::options(),
        ]);
    }

    public function create()
    {
        return view('marketplace.settlements.create', ['stores' => MarketplaceStore::options()]);
    }

    public function store(Request $request, MarketplaceSettlementService $service)
    {
        $data = $request->validate([
            'marketplace_store_id' => ['required', Rule::exists('marketplace_stores', 'id')],
            'file' => ['required', 'file', 'max:20480', 'mimes:csv,txt,xlsx'],
            'date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ], [], ['marketplace_store_id' => 'toko', 'file' => 'file laporan penghasilan', 'date' => 'tanggal pencairan']);

        $file = $request->file('file');
        $settlement = $service->importFile(MarketplaceStore::findOrFail($data['marketplace_store_id']), $file->getRealPath(), $file->getClientOriginalName(), $data);

        return redirect()->route('marketplace-settlements.show', $settlement)->with('success', "Draft {$settlement->number} dibuat. Periksa lalu posting.");
    }

    public function show(MarketplaceSettlement $marketplaceSettlement)
    {
        $marketplaceSettlement->load(['store', 'lines.order', 'creator', 'poster', 'activities.user']);

        return view('marketplace.settlements.show', ['settlement' => $marketplaceSettlement]);
    }

    public function pdf(MarketplaceSettlement $marketplaceSettlement)
    {
        $marketplaceSettlement->load(['store', 'lines', 'creator', 'poster']);

        return DocumentPdf::stream('pdf.marketplace-settlement', ['settlement' => $marketplaceSettlement], $marketplaceSettlement->number, 'landscape');
    }

    public function post(MarketplaceSettlement $marketplaceSettlement, MarketplaceSettlementService $service)
    {
        $service->post($marketplaceSettlement);

        return back()->with('success', 'Pencairan diposting: piutang marketplace ditutup, biaya platform dijurnal.');
    }

    public function rematch(MarketplaceSettlement $marketplaceSettlement, MarketplaceSettlementService $service)
    {
        $count = $service->rematch($marketplaceSettlement);

        return back()->with('success', "{$count} baris dicocokkan ke pesanan.");
    }

    public function cancel(Request $request, MarketplaceSettlement $marketplaceSettlement, MarketplaceSettlementService $service)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'alasan pembatalan']);
        $service->cancel($marketplaceSettlement, $data['reason']);

        return back()->with('success', 'Pencairan dibatalkan, jurnal dibalik.');
    }

    public function destroy(MarketplaceSettlement $marketplaceSettlement, MarketplaceSettlementService $service)
    {
        $service->delete($marketplaceSettlement);

        return redirect()->route('marketplace-settlements.index')->with('success', 'Draft pencairan dihapus.');
    }

    public function template()
    {
        $csv = MarketplaceImporter::template(MarketplaceSettlementService::TEMPLATE, [
            '2610250001ABC', '2026-10-30', '198000', '10000', '12000', '6000', '1000', '3000', '0', '0', '166000', 'Pesanan',
        ]);

        return response($csv, 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="template-pencairan-marketplace.csv"']);
    }
}
