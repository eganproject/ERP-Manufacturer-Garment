<?php

namespace App\Http\Controllers\Assets;

use App\Enums\AssetAcquisitionType;
use App\Enums\AssetDisposalType;
use App\Enums\DepreciationMethod;
use App\Enums\FixedAssetStatus;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AssetCategory;
use App\Models\FixedAsset;
use App\Models\Supplier;
use App\Services\DepreciationService;
use App\Services\FixedAssetService;
use App\Services\Reports\FixedAssetReport;
use App\Support\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Register aset tetap (sekaligus laporan daftar aset & penyusutan) dan transaksi per aset.
 */
class FixedAssetController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:assets.view', only: ['index', 'show']),
            new Middleware('can:assets.manage', except: ['index', 'show']),
        ];
    }

    public function index(Request $request, FixedAssetReport $report)
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'category_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::enum(FixedAssetStatus::class)],
            'export' => ['nullable', Rule::in(['xlsx', 'pdf'])],
        ]);
        $to = $request->to ? Carbon::parse($request->to) : today();
        $from = $request->from ? Carbon::parse($request->from) : $to->copy()->startOfYear();
        $categories = AssetCategory::orderBy('code')->pluck('name', 'id');
        $data = $report->build($from, $to, $request->integer('category_id') ?: null, $request->status, $request->q);

        if ($response = ReportExport::respond($report->table($data, $from, $to, $categories[$request->integer('category_id')] ?? null), $request->query('export'))) {
            return $response;
        }

        return view('assets.index', compact('data', 'from', 'to', 'categories'));
    }

    public function create(Request $request)
    {
        $type = AssetAcquisitionType::tryFrom((string) $request->query('type')) ?? AssetAcquisitionType::Cash;

        return view('assets.create', [
            'type' => $type,
            'categories' => AssetCategory::where('is_active', true)->orderBy('code')->get(),
            'suppliers' => Supplier::orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function store(Request $request, FixedAssetService $service)
    {
        $type = AssetAcquisitionType::from($request->validate(['acquisition_type' => ['required', Rule::enum(AssetAcquisitionType::class)]])['acquisition_type']);

        $data = $request->validate([
            'acquisition_type' => ['required'],
            'name' => ['required', 'string', 'max:255'],
            'asset_category_id' => ['required', 'exists:asset_categories,id'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:100'],
            'acquisition_date' => ['required', 'date'],
            'cost' => ['required', 'numeric', 'min:1'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'supplier_id' => [$type === AssetAcquisitionType::Credit ? 'required' : 'nullable', 'exists:suppliers,id'],
            'cash_account_id' => [$type === AssetAcquisitionType::Cash ? 'required' : 'nullable', Account::cashRule()],
            'due_date' => ['nullable', 'date', 'after_or_equal:acquisition_date'],
            'reference' => ['nullable', 'string', 'max:100'],
            ...$this->depreciationRules(),
            'opening_accumulated' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
        ], [], $this->attributes());

        $asset = $service->create($data);

        return redirect()->route('fixed-assets.show', $asset)->with('success', "Aset {$asset->number} tercatat dan dijurnal.");
    }

    public function show(FixedAsset $fixedAsset, DepreciationService $depreciation)
    {
        $fixedAsset->load(['category', 'supplier', 'cashAccount', 'bill', 'assetAccount', 'accumulatedAccount', 'expenseAccount', 'disposalAccount', 'creator', 'activities.user']);
        $schedule = $depreciation->schedule($fixedAsset);
        $pending = $fixedAsset->isActive() ? $depreciation->pendingFor($fixedAsset, today()->startOfMonth()->subMonthNoOverflow()) : [];

        return view('assets.show', [
            'asset' => $fixedAsset,
            'schedule' => $schedule,
            'pending' => $pending,
            'cashAccounts' => Account::cashOptions(),
        ]);
    }

    public function edit(FixedAsset $fixedAsset)
    {
        return view('assets.edit', ['asset' => $fixedAsset->load('category')]);
    }

    public function update(Request $request, FixedAsset $fixedAsset, FixedAssetService $service)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'location' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            ...($fixedAsset->canEditDepreciation() ? $this->depreciationRules(true) : []),
        ], [], $this->attributes());

        $service->update($fixedAsset, $data);

        return redirect()->route('fixed-assets.show', $fixedAsset)->with('success', 'Data aset diperbarui.');
    }

    public function cancel(Request $request, FixedAsset $fixedAsset, FixedAssetService $service)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'alasan pembatalan']);
        $service->cancel($fixedAsset, $data['reason']);

        return back()->with('success', "Aset {$fixedAsset->number} dibatalkan dan jurnalnya dibalik.");
    }

    public function dispose(Request $request, FixedAsset $fixedAsset, FixedAssetService $service)
    {
        $data = $request->validate([
            'disposal_date' => ['required', 'date'],
            'disposal_type' => ['required', Rule::enum(AssetDisposalType::class)],
            'disposal_proceeds' => ['nullable', 'numeric', 'min:0'],
            'disposal_account_id' => ['nullable', Account::cashRule()],
            'disposal_notes' => ['nullable', 'string', 'max:255'],
        ], [], [
            'disposal_date' => 'tanggal pelepasan',
            'disposal_proceeds' => 'harga jual',
            'disposal_account_id' => 'rekening penerimaan',
        ]);
        $service->dispose($fixedAsset, $data);
        $gain = $fixedAsset->fresh()->disposalGain();

        return back()->with('success', 'Aset dilepas dengan '.($gain >= 0 ? 'laba ' : 'rugi ').rupiah(abs($gain)).'.');
    }

    public function cancelDisposal(Request $request, FixedAsset $fixedAsset, FixedAssetService $service)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'alasan']);
        $service->cancelDisposal($fixedAsset, $data['reason']);

        return back()->with('success', 'Pelepasan aset dibatalkan, aset kembali aktif.');
    }

    private function depreciationRules(bool $required = false): array
    {
        return [
            'depreciation_method' => [$required ? 'required' : 'nullable', Rule::enum(DepreciationMethod::class)],
            'useful_life_months' => [$required ? 'required' : 'nullable', 'integer', 'min:0', 'max:600'],
            'residual_value' => ['nullable', 'numeric', 'min:0'],
            'depreciation_start' => ['nullable', 'date'],
        ];
    }

    private function attributes(): array
    {
        return [
            'name' => 'nama aset',
            'asset_category_id' => 'kategori',
            'acquisition_date' => 'tanggal perolehan',
            'cost' => 'harga perolehan',
            'supplier_id' => 'supplier',
            'cash_account_id' => 'rekening kas/bank',
            'useful_life_months' => 'masa manfaat',
            'residual_value' => 'nilai sisa',
            'depreciation_start' => 'mulai penyusutan',
            'opening_accumulated' => 'akumulasi penyusutan awal',
        ];
    }
}
