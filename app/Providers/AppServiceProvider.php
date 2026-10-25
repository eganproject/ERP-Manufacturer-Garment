<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\AccountingPeriod;
use App\Models\Bill;
use App\Models\Budget;
use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\DefectHandling;
use App\Models\Delivery;
use App\Models\DepreciationRun;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\FixedAsset;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceSettlement;
use App\Models\MarketplaceStore;
use App\Models\Material;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\PieceworkSheet;
use App\Models\ProductionOrder;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockTransfer;
use App\Models\SubcontractOrder;
use App\Models\SubcontractReceipt;
use App\Models\SubcontractTransfer;
use App\Models\Supplier;
use App\Models\User;
use App\Models\VatPeriod;
use App\Models\Warehouse;
use App\Support\LedgerAccounts;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Cache pemetaan akun per request
        $this->app->scoped(LedgerAccounts::class);
    }

    public function boot(): void
    {
        Carbon::setLocale(config('app.locale'));

        // Nama tipe yang stabil untuk relasi polimorfik (stok & referensi dokumen)
        Relation::enforceMorphMap([
            'material' => Material::class,
            'product_variant' => ProductVariant::class,
            'purchase_order' => PurchaseOrder::class,
            'goods_receipt' => GoodsReceipt::class,
            'production_order' => ProductionOrder::class,
            'sales_order' => SalesOrder::class,
            'delivery' => Delivery::class,
            'invoice' => Invoice::class,
            'bill' => Bill::class,
            'payment' => Payment::class,
            'subcontract_order' => SubcontractOrder::class,
            'subcontract_transfer' => SubcontractTransfer::class,
            'subcontract_receipt' => SubcontractReceipt::class,
            'sales_return' => SalesReturn::class,
            'purchase_return' => PurchaseReturn::class,
            'stock_movement' => StockMovement::class,
            'customer' => Customer::class,
            'supplier' => Supplier::class,
            'journal_entry' => JournalEntry::class,
            'accounting_period' => AccountingPeriod::class,
            'user' => User::class,
            'employee' => Employee::class,
            'employee_advance' => EmployeeAdvance::class,
            'payroll' => Payroll::class,
            'piecework_sheet' => PieceworkSheet::class,
            'cash_transaction' => CashTransaction::class,
            'budget' => Budget::class,
            'fixed_asset' => FixedAsset::class,
            'depreciation_run' => DepreciationRun::class,
            'defect_handling' => DefectHandling::class,
            'vat_period' => VatPeriod::class,
            'quotation' => Quotation::class,
            'stock_transfer' => StockTransfer::class,
            'stock_opname' => StockOpname::class,
            'warehouse' => Warehouse::class,
            'marketplace_store' => MarketplaceStore::class,
            'marketplace_order' => MarketplaceOrder::class,
            'marketplace_settlement' => MarketplaceSettlement::class,
        ]);

        // Hak akses berbasis peran (lihat App\Enums\Role)
        Gate::before(fn (User $user) => $user->is_active && $user->isAdmin() ? true : null);

        foreach (array_keys(Role::abilities()) as $ability) {
            Gate::define($ability, fn (User $user) => $user->hasPermission($ability));
        }
    }
}
