<?php

use App\Http\Controllers\Accounting\AccountController;
use App\Http\Controllers\Accounting\BudgetController;
use App\Http\Controllers\Accounting\FinancialReportController;
use App\Http\Controllers\Accounting\JournalController;
use App\Http\Controllers\Accounting\PeriodController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\AgingController;
use App\Http\Controllers\Assets\AssetCategoryController;
use App\Http\Controllers\Assets\DepreciationController;
use App\Http\Controllers\Assets\FixedAssetController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BillController;
use App\Http\Controllers\BomController;
use App\Http\Controllers\CashBankController;
use App\Http\Controllers\CostingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DefectController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\Marketplace\MarketplaceMappingController;
use App\Http\Controllers\Marketplace\MarketplaceOrderController;
use App\Http\Controllers\Marketplace\MarketplaceSettlementController;
use App\Http\Controllers\Marketplace\MarketplaceStockController;
use App\Http\Controllers\Marketplace\MarketplaceStoreController;
use App\Http\Controllers\Master\CategoryController;
use App\Http\Controllers\Master\CustomerController;
use App\Http\Controllers\Master\MaterialController;
use App\Http\Controllers\Master\ProductController;
use App\Http\Controllers\Master\SupplierController;
use App\Http\Controllers\Master\UnitController;
use App\Http\Controllers\MrpController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\Payroll\EmployeeAdvanceController;
use App\Http\Controllers\Payroll\EmployeeController;
use App\Http\Controllers\Payroll\PayrollController;
use App\Http\Controllers\Payroll\PieceRateController;
use App\Http\Controllers\Payroll\PieceworkController;
use App\Http\Controllers\ProductionOrderController;
use App\Http\Controllers\ProductionPlanController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\PurchaseReturnController;
use App\Http\Controllers\QuotationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SalesOrderController;
use App\Http\Controllers\SalesReturnController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockOpnameController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\SubcontractOrderController;
use App\Http\Controllers\TaxController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store']);
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');

    // Profil pengguna yang sedang login
    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'password'])->name('profile.password');

    // Master data
    Route::prefix('master')->group(function () {
        Route::resource('units', UnitController::class)->except('show')->middleware('can:master.basic');
        Route::resource('categories', CategoryController::class)->except('show')->middleware('can:master.basic');
        Route::resource('suppliers', SupplierController::class)->except('show')->middleware('can:suppliers.manage');
        Route::resource('customers', CustomerController::class)->except('show')->middleware('can:customers.manage');
        Route::resource('materials', MaterialController::class)->except('show')->middleware('can:materials.manage');
        Route::resource('products', ProductController::class)->middleware('can:products.manage');
    });

    // Impor data massal dari Excel / CSV (hak akses per jenis dicek di controller)
    Route::controller(ImportController::class)->prefix('imports')->name('imports.')->middleware('can:imports.use')->group(function () {
        Route::get('/', 'index')->name('index');
        Route::get('{type}', 'show')->name('show');
        Route::get('{type}/template', 'template')->name('template');
        Route::post('{type}', 'store')->name('store');
    });

    // Bill of Materials
    Route::resource('boms', BomController::class)->middleware('can:boms.manage');
    Route::get('boms/{bom}/pdf', [BomController::class, 'pdf'])->name('boms.pdf')->middleware('can:boms.manage');

    // Pembelian
    Route::resource('purchase-orders', PurchaseOrderController::class);
    Route::controller(PurchaseOrderController::class)->prefix('purchase-orders/{purchase_order}')->name('purchase-orders.')->group(function () {
        Route::post('submit', 'submit')->name('submit');
        Route::post('approve', 'approve')->name('approve');
        Route::post('reject', 'reject')->name('reject');
        Route::post('cancel', 'cancel')->name('cancel');
        Route::post('close', 'close')->name('close');
        Route::get('receive', 'receiveForm')->name('receive');
        Route::post('receive', 'receive')->name('receive.store');
        Route::get('pdf', 'pdf')->name('pdf');
    });
    Route::get('goods-receipts/{receipt}/pdf', [PurchaseOrderController::class, 'receiptPdf'])->name('goods-receipts.pdf');

    // Penawaran harga → Sales Order
    Route::resource('quotations', QuotationController::class);
    Route::controller(QuotationController::class)->prefix('quotations/{quotation}')->name('quotations.')->group(function () {
        Route::post('send', 'send')->name('send');
        Route::post('convert', 'convert')->name('convert');
        Route::post('reject', 'reject')->name('reject');
        Route::post('cancel', 'cancel')->name('cancel');
        Route::post('revise', 'revise')->name('revise');
        Route::get('pdf', 'pdf')->name('pdf');
    });

    // Penjualan
    Route::resource('sales-orders', SalesOrderController::class);
    Route::controller(SalesOrderController::class)->prefix('sales-orders/{sales_order}')->name('sales-orders.')->group(function () {
        Route::post('confirm', 'confirm')->name('confirm');
        Route::get('deliver', 'deliverForm')->name('deliver');
        Route::post('deliver', 'deliver')->name('deliver.store');
        Route::post('cancel', 'cancel')->name('cancel');
        Route::post('close', 'close')->name('close');
        Route::get('pdf', 'pdf')->name('pdf');
    });
    Route::get('deliveries/{delivery}/pdf', [SalesOrderController::class, 'deliveryPdf'])->name('deliveries.pdf');

    // Retur penjualan & pembelian (nota kredit / debit)
    foreach (['sales-returns' => SalesReturnController::class, 'purchase-returns' => PurchaseReturnController::class] as $name => $controller) {
        $param = str_replace('-', '_', str($name)->singular());
        Route::resource($name, $controller)->only(['index', 'create', 'store', 'show']);
        Route::controller($controller)->prefix("{$name}/{{$param}}")->name("{$name}.")->group(function () {
            Route::post('cancel', 'cancel')->name('cancel');
            Route::post('apply', 'apply')->name('apply');
            Route::post('refund', 'refund')->name('refund');
            Route::get('pdf', 'pdf')->name('pdf');
        });
    }

    // Produksi (SPK)
    Route::resource('production-orders', ProductionOrderController::class);
    Route::controller(ProductionOrderController::class)->prefix('production-orders/{production_order}')->name('production-orders.')->group(function () {
        Route::post('start', 'start')->name('start');
        Route::put('stages/{stage}', 'updateStage')->name('stages.update');
        Route::post('complete', 'complete')->name('complete');
        Route::post('cancel', 'cancel')->name('cancel');
        Route::get('pdf', 'pdf')->name('pdf');
        Route::post('costs', 'storeCost')->name('costs.store');
        Route::delete('costs/{cost}', 'destroyCost')->name('costs.destroy');
    });

    // Produksi maklon (subkontrak ke vendor luar)
    Route::resource('subcontract-orders', SubcontractOrderController::class)->only(['index', 'create', 'store', 'show']);
    Route::controller(SubcontractOrderController::class)->prefix('subcontract-orders/{subcontract_order}')->name('subcontract-orders.')->group(function () {
        Route::get('transfer/{type}', 'transferForm')->name('transfer')->whereIn('type', ['dispatch', 'return']);
        Route::post('transfer/{type}', 'transfer')->name('transfer.store')->whereIn('type', ['dispatch', 'return']);
        Route::get('receive', 'receiveForm')->name('receive');
        Route::post('receive', 'receive')->name('receive.store');
        Route::post('close', 'close')->name('close');
        Route::post('cancel', 'cancel')->name('cancel');
        Route::get('pdf', 'pdf')->name('pdf');
    });
    Route::get('subcontract-transfers/{transfer}/pdf', [SubcontractOrderController::class, 'transferPdf'])->name('subcontract-transfers.pdf');
    Route::get('subcontract-receipts/{receipt}/pdf', [SubcontractOrderController::class, 'receiptPdf'])->name('subcontract-receipts.pdf');

    // Upah borongan karyawan produksi
    Route::resource('employees', EmployeeController::class);
    Route::post('employees/{employee}/advances', [EmployeeAdvanceController::class, 'store'])->name('employees.advances.store')->middleware('can:payroll.pay');
    Route::post('employee-advances/{advance}/cancel', [EmployeeAdvanceController::class, 'cancel'])->name('employee-advances.cancel')->middleware('can:payroll.pay');
    Route::get('employee-advances/{advance}/pdf', [EmployeeAdvanceController::class, 'pdf'])->name('employee-advances.pdf')->middleware('can:payroll.view');
    Route::controller(PieceRateController::class)->middleware('can:employees.manage')->group(function () {
        Route::get('piece-rates', 'index')->name('piece-rates.index');
        Route::get('piece-rates/{product}', 'edit')->name('piece-rates.edit');
        Route::put('piece-rates/{product}', 'update')->name('piece-rates.update');
    });
    Route::resource('piecework', PieceworkController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
    Route::get('piecework/{piecework}/pdf', [PieceworkController::class, 'pdf'])->name('piecework.pdf');
    Route::resource('payrolls', PayrollController::class)->only(['index', 'create', 'store', 'show', 'update']);
    Route::controller(PayrollController::class)->prefix('payrolls/{payroll}')->name('payrolls.')->group(function () {
        Route::post('recalculate', 'recalculate')->name('recalculate');
        Route::post('approve', 'approve')->name('approve');
        Route::post('pay', 'pay')->name('pay');
        Route::post('cancel', 'cancel')->name('cancel');
        Route::get('pdf', 'pdf')->name('pdf');
        Route::get('slips', 'slips')->name('slips');
        Route::get('slips/{line}', 'slip')->name('slip');
    });

    // Perencanaan & biaya produksi
    Route::get('planning/mrp', [MrpController::class, 'index'])->name('mrp.index');
    Route::post('planning/mrp/purchase-orders', [MrpController::class, 'store'])->name('mrp.purchase-orders');
    Route::get('planning/production', [ProductionPlanController::class, 'index'])->name('production-plan.index');
    Route::post('planning/production', [ProductionPlanController::class, 'store'])->name('production-plan.store');
    Route::get('reports/production-costing', CostingController::class)->name('reports.costing')->middleware('can:costing.view');

    // Marketplace TikTok Shop & Shopee: toko, mapping SKU, pesanan & retur, alokasi stok, pencairan dana
    Route::prefix('marketplace')->group(function () {
        Route::resource('stores', MarketplaceStoreController::class)->except('show')->names('marketplace-stores')->parameters(['stores' => 'marketplaceStore']);
        Route::post('stores/{marketplaceStore}/sync', [MarketplaceStoreController::class, 'sync'])->name('marketplace-stores.sync');

        Route::controller(MarketplaceMappingController::class)->prefix('mappings')->name('marketplace-mappings.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('/', 'store')->name('store');
            Route::post('auto', 'auto')->name('auto');
            Route::post('preorder', 'preorder')->name('preorder');
            Route::delete('{mapping}', 'destroy')->name('destroy');
        });

        Route::controller(MarketplaceOrderController::class)->prefix('orders')->name('marketplace-orders.')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::get('import', 'importForm')->name('import');
            Route::post('import', 'import')->name('import.store');
            Route::get('template', 'template')->name('template');
            Route::post('ship', 'shipMany')->name('ship-many');
            Route::get('picking-list', 'pickingList')->name('picking');
            Route::get('{marketplaceOrder}', 'show')->name('show');
            Route::post('{marketplaceOrder}/ship', 'ship')->name('ship');
            Route::post('{marketplaceOrder}/schedule', 'schedule')->name('schedule');
            Route::post('{marketplaceOrder}/complete', 'complete')->name('complete');
            Route::post('{marketplaceOrder}/cancel', 'cancel')->name('cancel');
            Route::post('{marketplaceOrder}/request-return', 'requestReturn')->name('request-return');
            Route::post('{marketplaceOrder}/reject-return', 'rejectReturn')->name('reject-return');
            Route::post('{marketplaceOrder}/return', 'receiveReturn')->name('return');
            Route::post('{marketplaceOrder}/claim', 'openClaim')->name('claim');
            Route::post('{marketplaceOrder}/claim/resolve', 'resolveClaim')->name('claim.resolve');
        });

        Route::get('stock', [MarketplaceStockController::class, 'index'])->name('marketplace-stock.index')->middleware('can:marketplace.view');

        Route::get('settlements/template', [MarketplaceSettlementController::class, 'template'])->name('marketplace-settlements.template');
        Route::resource('settlements', MarketplaceSettlementController::class)->except(['edit', 'update'])->names('marketplace-settlements')->parameters(['settlements' => 'marketplaceSettlement']);
        Route::controller(MarketplaceSettlementController::class)->prefix('settlements/{marketplaceSettlement}')->name('marketplace-settlements.')->group(function () {
            Route::post('post', 'post')->name('post');
            Route::post('rematch', 'rematch')->name('rematch');
            Route::get('pdf', 'pdf')->name('pdf');
            Route::post('cancel', 'cancel')->name('cancel');
        });
    });

    // Keuangan: piutang (invoice & penerimaan) dan hutang (tagihan & pembayaran supplier)
    Route::resource('invoices', InvoiceController::class)->only(['index', 'create', 'store', 'show']);
    Route::post('invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->name('invoices.cancel');
    Route::get('invoices/{invoice}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');

    Route::resource('bills', BillController::class)->only(['index', 'create', 'store', 'show']);
    Route::post('bills/{bill}/cancel', [BillController::class, 'cancel'])->name('bills.cancel');
    Route::get('bills/{bill}/pdf', [BillController::class, 'pdf'])->name('bills.pdf');

    Route::controller(PaymentController::class)->group(function () {
        Route::get('receipts', 'index')->name('receipts.index')->defaults('type', 'receipt');
        Route::get('receipts/create', 'create')->name('receipts.create')->defaults('type', 'receipt');
        Route::get('disbursements', 'index')->name('disbursements.index')->defaults('type', 'disbursement');
        Route::get('disbursements/create', 'create')->name('disbursements.create')->defaults('type', 'disbursement');
        Route::post('payments', 'store')->name('payments.store');
        Route::get('payments/{payment}', 'show')->name('payments.show');
        Route::post('payments/{payment}/cancel', 'cancel')->name('payments.cancel');
        Route::get('payments/{payment}/pdf', 'pdf')->name('payments.pdf');
    });

    // Kas & bank: rekening, mutasi, kas masuk/keluar, transfer antar rekening
    Route::controller(CashBankController::class)->group(function () {
        Route::get('cash-bank', 'index')->name('cash-bank.index');
        Route::get('cash-bank/accounts/{account}', 'statement')->name('cash-bank.statement');
        Route::get('cash-transactions', 'transactions')->name('cash-transactions.index');
        Route::get('cash-transactions/create', 'create')->name('cash-transactions.create');
        Route::post('cash-transactions', 'store')->name('cash-transactions.store');
        Route::get('cash-transactions/{cashTransaction}', 'show')->name('cash-transactions.show');
        Route::post('cash-transactions/{cashTransaction}/cancel', 'cancel')->name('cash-transactions.cancel');
        Route::get('cash-transactions/{cashTransaction}/pdf', 'pdf')->name('cash-transactions.pdf');
    });

    // Pajak: laporan PPN per masa, tutup masa, nomor faktur pajak
    Route::controller(TaxController::class)->group(function () {
        Route::get('reports/vat', 'report')->name('reports.vat')->middleware('can:tax.view');
        Route::middleware('can:tax.manage')->group(function () {
            Route::post('vat-periods', 'close')->name('vat-periods.store');
            Route::post('vat-periods/{vatPeriod}/cancel', 'cancel')->name('vat-periods.cancel');
            Route::put('invoices/{invoice}/tax-invoice', 'invoiceNumber')->name('invoices.tax-invoice');
            Route::put('bills/{bill}/tax-invoice', 'billNumber')->name('bills.tax-invoice');
        });
    });

    Route::get('reports/receivables-aging', [AgingController::class, 'receivables'])->name('reports.receivables-aging')->middleware('can:receivables.view');
    Route::get('reports/payables-aging', [AgingController::class, 'payables'])->name('reports.payables-aging')->middleware('can:payables.view');

    // Laporan
    Route::controller(ReportController::class)->prefix('reports')->name('reports.')->group(function () {
        Route::get('sales', 'sales')->name('sales')->middleware('can:receivables.view');
        Route::get('purchases', 'purchases')->name('purchases')->middleware('can:payables.view');
        Route::get('production', 'production')->name('production')->middleware('can:production.view');
        Route::get('stock-valuation', 'stockValuation')->name('stock-valuation')->middleware('can:costing.view');
        Route::get('subcontract', 'subcontract')->name('subcontract')->middleware('can:production.view');
        Route::get('labor', 'labor')->name('labor')->middleware('can:payroll.view');
        Route::get('marketplace', 'marketplace')->name('marketplace')->middleware('can:marketplace.view');
    });

    // Akuntansi: bagan akun, jurnal, tutup buku, laporan keuangan
    Route::prefix('accounting')->group(function () {
        Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index')->middleware('can:accounting.view');
        Route::middleware('can:accounting.manage')->group(function () {
            Route::resource('accounts', AccountController::class)->except(['index', 'show']);
            Route::get('account-mapping', [AccountController::class, 'mapping'])->name('accounts.mapping');
            Route::put('account-mapping', [AccountController::class, 'updateMapping'])->name('accounts.mapping.update');
        });

        Route::resource('journals', JournalController::class)->only(['index', 'create', 'store', 'show']);
        Route::post('journals/{journal}/reverse', [JournalController::class, 'reverse'])->name('journals.reverse');
        Route::get('journals/{journal}/pdf', [JournalController::class, 'pdf'])->name('journals.pdf');
        Route::post('journals-rebuild', [JournalController::class, 'rebuild'])->name('journals.rebuild');

        Route::controller(PeriodController::class)->prefix('periods')->name('periods.')->middleware('can:accounting.close')->group(function () {
            Route::get('/', 'index')->name('index');
            Route::post('close', 'close')->name('close');
            Route::post('reopen', 'reopen')->name('reopen');
        });

        Route::controller(FinancialReportController::class)->prefix('reports')->name('reports.')->middleware('can:accounting.view')->group(function () {
            Route::get('general-ledger', 'ledger')->name('ledger');
            Route::get('trial-balance', 'trialBalance')->name('trial-balance');
            Route::get('profit-loss', 'profitLoss')->name('profit-loss');
            Route::get('balance-sheet', 'balanceSheet')->name('balance-sheet');
            Route::get('cash-flow', 'cashFlow')->name('cash-flow');
        });

        Route::resource('budgets', BudgetController::class);
    });

    // Persediaan
    Route::controller(StockController::class)->prefix('stock')->name('stock.')->middleware('can:stock.view')->group(function () {
        Route::get('materials', 'materials')->name('materials');
        Route::get('materials/{material}', 'materialCard')->name('materials.card');
        Route::get('products', 'products')->name('products');
        Route::get('products/{variant}', 'productCard')->name('products.card');
        Route::get('adjustment', 'adjustmentForm')->name('adjustment')->middleware('can:stock.adjust');
        Route::post('adjustment', 'adjust')->name('adjustment.store')->middleware('can:stock.adjust');
    });

    // Multi-gudang: master gudang, transfer antar gudang, stock opname per gudang
    Route::resource('warehouses', WarehouseController::class);
    Route::resource('stock-transfers', StockTransferController::class)->only(['index', 'create', 'store', 'show']);
    Route::post('stock-transfers/{stock_transfer}/cancel', [StockTransferController::class, 'cancel'])->name('stock-transfers.cancel');
    Route::get('stock-transfers/{stock_transfer}/pdf', [StockTransferController::class, 'pdf'])->name('stock-transfers.pdf');
    Route::resource('stock-opnames', StockOpnameController::class)->only(['index', 'create', 'store', 'show', 'update']);
    Route::post('stock-opnames/{stock_opname}/cancel', [StockOpnameController::class, 'cancel'])->name('stock-opnames.cancel');
    Route::get('stock-opnames/{stock_opname}/pdf', [StockOpnameController::class, 'pdf'])->name('stock-opnames.pdf');

    // Barang cacat (karantina) dari retur penjualan / temuan gudang
    Route::resource('defects', DefectController::class)->only(['index', 'create', 'store', 'show']);
    Route::post('defects/{defect}/cancel', [DefectController::class, 'cancel'])->name('defects.cancel');
    Route::get('defects/{defect}/pdf', [DefectController::class, 'pdf'])->name('defects.pdf');

    // Aset tetap & penyusutan
    Route::resource('asset-categories', AssetCategoryController::class)->except('show');
    Route::resource('fixed-assets', FixedAssetController::class)->except('destroy');
    Route::controller(FixedAssetController::class)->prefix('fixed-assets/{fixed_asset}')->name('fixed-assets.')->group(function () {
        Route::post('cancel', 'cancel')->name('cancel');
        Route::post('dispose', 'dispose')->name('dispose');
        Route::post('cancel-disposal', 'cancelDisposal')->name('cancel-disposal');
    });
    Route::resource('depreciation-runs', DepreciationController::class)->only(['index', 'store', 'show']);
    Route::post('depreciation-runs/{depreciation_run}/cancel', [DepreciationController::class, 'cancel'])->name('depreciation-runs.cancel');

    // Administrasi
    Route::prefix('admin')->group(function () {
        Route::resource('users', UserController::class)->except('show')->middleware('can:users.manage');
        Route::get('settings', [SettingController::class, 'edit'])->name('settings.edit')->middleware('can:settings.manage');
        Route::put('settings', [SettingController::class, 'update'])->name('settings.update')->middleware('can:settings.manage');
        Route::get('activity-logs', ActivityLogController::class)->name('activity-logs.index')->middleware('can:activity.view');
    });
});
