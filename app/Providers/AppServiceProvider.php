<?php

namespace App\Providers;

use App\Models\GoodsReceipt;
use App\Models\Material;
use App\Models\ProductionOrder;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Carbon;
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
            'goods_receipt' => GoodsReceipt::class,
            'production_order' => ProductionOrder::class,
            'sales_order' => SalesOrder::class,
            'user' => User::class,
        ]);
    }
}
