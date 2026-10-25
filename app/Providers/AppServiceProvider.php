<?php

namespace App\Providers;

use App\Enums\Role;
use App\Models\Bill;
use App\Models\Delivery;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\Material;
use App\Models\Payment;
use App\Models\ProductionOrder;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
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
            'sales_return' => SalesReturn::class,
            'purchase_return' => PurchaseReturn::class,
            'user' => User::class,
        ]);

        // Hak akses berbasis peran (lihat App\Enums\Role)
        Gate::before(fn (User $user) => $user->is_active && $user->isAdmin() ? true : null);

        foreach (array_keys(Role::abilities()) as $ability) {
            Gate::define($ability, fn (User $user) => $user->hasPermission($ability));
        }
    }
}
