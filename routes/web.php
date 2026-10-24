<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BomController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Master\CategoryController;
use App\Http\Controllers\Master\CustomerController;
use App\Http\Controllers\Master\MaterialController;
use App\Http\Controllers\Master\ProductController;
use App\Http\Controllers\Master\SupplierController;
use App\Http\Controllers\Master\UnitController;
use App\Http\Controllers\ProductionOrderController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\SalesOrderController;
use App\Http\Controllers\StockController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', DashboardController::class)->name('dashboard');

    // Master data
    Route::prefix('master')->group(function () {
        Route::resource('units', UnitController::class)->except('show');
        Route::resource('categories', CategoryController::class)->except('show');
        Route::resource('suppliers', SupplierController::class)->except('show');
        Route::resource('customers', CustomerController::class)->except('show');
        Route::resource('materials', MaterialController::class)->except('show');
        Route::resource('products', ProductController::class);
    });

    // Bill of Materials
    Route::resource('boms', BomController::class);

    // Pembelian
    Route::resource('purchase-orders', PurchaseOrderController::class);
    Route::controller(PurchaseOrderController::class)->prefix('purchase-orders/{purchase_order}')->name('purchase-orders.')->group(function () {
        Route::post('submit', 'submit')->name('submit');
        Route::post('cancel', 'cancel')->name('cancel');
        Route::get('receive', 'receiveForm')->name('receive');
        Route::post('receive', 'receive')->name('receive.store');
    });

    // Penjualan
    Route::resource('sales-orders', SalesOrderController::class);
    Route::controller(SalesOrderController::class)->prefix('sales-orders/{sales_order}')->name('sales-orders.')->group(function () {
        Route::post('confirm', 'confirm')->name('confirm');
        Route::post('deliver', 'deliver')->name('deliver');
        Route::post('cancel', 'cancel')->name('cancel');
    });

    // Produksi (SPK)
    Route::resource('production-orders', ProductionOrderController::class);
    Route::controller(ProductionOrderController::class)->prefix('production-orders/{production_order}')->name('production-orders.')->group(function () {
        Route::post('start', 'start')->name('start');
        Route::put('stages/{stage}', 'updateStage')->name('stages.update');
        Route::post('complete', 'complete')->name('complete');
        Route::post('cancel', 'cancel')->name('cancel');
    });

    // Persediaan
    Route::controller(StockController::class)->prefix('stock')->name('stock.')->group(function () {
        Route::get('materials', 'materials')->name('materials');
        Route::get('materials/{material}', 'materialCard')->name('materials.card');
        Route::get('products', 'products')->name('products');
        Route::get('products/{variant}', 'productCard')->name('products.card');
        Route::get('adjustment', 'adjustmentForm')->name('adjustment');
        Route::post('adjustment', 'adjust')->name('adjustment.store');
    });
});
