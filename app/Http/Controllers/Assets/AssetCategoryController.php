<?php

namespace App\Http\Controllers\Assets;

use App\Enums\AccountType;
use App\Enums\DepreciationMethod;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AssetCategory;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;

class AssetCategoryController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:assets.view', only: ['index']),
            new Middleware('can:assets.manage', except: ['index']),
        ];
    }

    public function index()
    {
        $categories = AssetCategory::with(['assetAccount', 'accumulatedAccount', 'expenseAccount'])
            ->withCount(['assets' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('code')->get();

        return view('assets.categories.index', compact('categories'));
    }

    public function create()
    {
        return view('assets.categories.form', ['category' => new AssetCategory(['is_active' => true, 'depreciation_method' => DepreciationMethod::StraightLine, 'useful_life_months' => 48])] + $this->options());
    }

    public function store(Request $request)
    {
        AssetCategory::create($this->validated($request));

        return redirect()->route('asset-categories.index')->with('success', 'Kategori aset berhasil ditambahkan.');
    }

    public function edit(AssetCategory $assetCategory)
    {
        return view('assets.categories.form', ['category' => $assetCategory] + $this->options());
    }

    public function update(Request $request, AssetCategory $assetCategory)
    {
        $assetCategory->update($this->validated($request, $assetCategory));

        return redirect()->route('asset-categories.index')->with('success', 'Kategori aset berhasil diperbarui. Aset yang sudah tercatat tetap memakai akun & metode lamanya.');
    }

    public function destroy(AssetCategory $assetCategory)
    {
        if ($assetCategory->assets()->exists()) {
            return back()->with('error', 'Kategori masih dipakai aset. Nonaktifkan saja bila tidak dipakai lagi.');
        }

        $assetCategory->delete();

        return back()->with('success', 'Kategori aset dihapus.');
    }

    private function options(): array
    {
        $accounts = fn (array $types) => Account::postable()->where('is_cash', false)->whereIn('type', $types)->orderBy('code')->get()
            ->mapWithKeys(fn ($a) => [$a->id => $a->display_name]);

        return [
            'assetAccounts' => $accounts([AccountType::Asset->value]),
            'expenseAccounts' => $accounts([AccountType::CostOfSales->value, AccountType::Expense->value, AccountType::OtherExpense->value]),
        ];
    }

    private function validated(Request $request, ?AssetCategory $category = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', Rule::unique('asset_categories')->ignore($category)],
            'name' => ['required', 'string', 'max:100'],
            'depreciation_method' => ['required', Rule::enum(DepreciationMethod::class)],
            'useful_life_months' => ['required', 'integer', 'min:0', 'max:600'],
            'residual_rate' => ['nullable', 'numeric', 'min:0', 'max:99'],
            'asset_account_id' => ['required', Rule::exists('accounts', 'id')->where('is_header', false)],
            'accumulated_account_id' => ['nullable', 'required_unless:useful_life_months,0', Rule::exists('accounts', 'id')->where('is_header', false)],
            'expense_account_id' => ['nullable', 'required_unless:useful_life_months,0', Rule::exists('accounts', 'id')->where('is_header', false)],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string'],
        ], [], [
            'useful_life_months' => 'masa manfaat',
            'asset_account_id' => 'akun aset',
            'accumulated_account_id' => 'akun akumulasi penyusutan',
            'expense_account_id' => 'akun beban penyusutan',
        ]);

        $data['residual_rate'] = (float) ($data['residual_rate'] ?? 0);

        return $data;
    }
}
