<?php

namespace App\Http\Controllers\Marketplace;

use App\Enums\MarketplacePlatform;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\MarketplaceStore;
use App\Models\Warehouse;
use App\Services\Marketplace\MarketplaceSyncService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

/** Toko TikTok Shop / Shopee: gudang sumber stok, rekening saldo penjual, PPN & kredensial API (opsional). */
class MarketplaceStoreController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:marketplace.view', only: ['index']),
            new Middleware('can:marketplace.manage', except: ['index']),
        ];
    }

    public function index()
    {
        $stores = MarketplaceStore::with(['warehouse', 'balanceAccount'])
            ->withCount(['orders', 'mappings', 'orders as to_ship_count' => fn ($q) => $q->where('status', 'to_ship')])
            ->orderBy('platform')->orderBy('name')->get();

        return view('marketplace.stores.index', compact('stores'));
    }

    public function create()
    {
        return view('marketplace.stores.form', $this->formData(new MarketplaceStore(['is_active' => true, 'platform' => MarketplacePlatform::Shopee, 'warehouse_id' => Warehouse::default()->id])));
    }

    public function store(Request $request)
    {
        $store = MarketplaceStore::create($this->validated($request));
        ActivityLog::record('created', "Menambah toko {$store->display_name}", $store);

        return redirect()->route('marketplace-stores.index')->with('success', "Toko {$store->display_name} ditambahkan.");
    }

    public function edit(MarketplaceStore $marketplaceStore)
    {
        return view('marketplace.stores.form', $this->formData($marketplaceStore));
    }

    public function update(Request $request, MarketplaceStore $marketplaceStore)
    {
        $marketplaceStore->update($this->validated($request, $marketplaceStore));
        ActivityLog::record('updated', "Mengubah toko {$marketplaceStore->display_name}", $marketplaceStore);

        return redirect()->route('marketplace-stores.index')->with('success', 'Toko diperbarui.');
    }

    public function destroy(MarketplaceStore $marketplaceStore)
    {
        if ($marketplaceStore->orders()->exists() || $marketplaceStore->settlements()->exists()) {
            return back()->with('error', 'Toko yang sudah memiliki pesanan / pencairan tidak bisa dihapus. Nonaktifkan saja.');
        }

        $marketplaceStore->delete();

        return redirect()->route('marketplace-stores.index')->with('success', 'Toko dihapus.');
    }

    public function sync(MarketplaceStore $marketplaceStore, MarketplaceSyncService $service)
    {
        $summary = $service->sync($marketplaceStore);

        return back()->with('success', "Sinkron API selesai: {$summary['created']} pesanan baru, {$summary['updated']} diperbarui".(count($summary['errors']) ? ', '.count($summary['errors']).' gagal' : '').'.');
    }

    private function formData(MarketplaceStore $store): array
    {
        return [
            'store' => $store,
            'platforms' => MarketplacePlatform::options(),
            'accounts' => Account::cashOptions(),
        ];
    }

    private function validated(Request $request, ?MarketplaceStore $store = null): array
    {
        $data = $request->validate([
            'platform' => ['required', Rule::enum(MarketplacePlatform::class)],
            'name' => ['required', 'string', 'max:255'],
            'shop_id' => ['nullable', 'string', 'max:60'],
            'warehouse_id' => ['required', Warehouse::rule()],
            'balance_account_id' => ['nullable', Account::cashRule()],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string'],
            'api_enabled' => ['boolean'],
            'api_partner_id' => ['nullable', 'string', 'max:100'],
            'api_partner_key' => ['nullable', 'string', 'max:500'],
            'api_access_token' => ['nullable', 'string', 'max:2000'],
            'api_refresh_token' => ['nullable', 'string', 'max:2000'],
            'api_shop_cipher' => ['nullable', 'string', 'max:120'],
        ], [], ['warehouse_id' => 'gudang', 'balance_account_id' => 'rekening saldo penjual', 'tax_rate' => 'PPN']);

        $data['tax_rate'] = (float) ($data['tax_rate'] ?? 0);
        $data['is_active'] = $request->boolean('is_active');
        $data['api_enabled'] = $request->boolean('api_enabled');

        // Rahasia API yang dikosongkan saat ubah = tetap memakai nilai lama
        foreach (['api_partner_key', 'api_access_token', 'api_refresh_token'] as $secret) {
            if ($store && empty($data[$secret])) {
                unset($data[$secret]);
            }
        }

        return $data;
    }
}
