<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pesanan marketplace PO (pre-order) vs ready stock: batas kirim per pesanan, hari PO per SKU,
 * hari proses pesanan ready stock per toko. Dipakai alokasi stok & rencana produksi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplace_stores', function (Blueprint $table) {
            $table->unsignedSmallInteger('handling_days')->default(2)->after('tax_rate'); // batas kirim ready stock (hari sejak dibayar)
        });

        Schema::table('marketplace_products', function (Blueprint $table) {
            $table->unsignedSmallInteger('preorder_days')->default(0)->after('units_per_item'); // 0 = ready stock
        });

        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->boolean('is_preorder')->default(false)->after('status');
            $table->date('ship_by')->nullable()->index()->after('is_preorder'); // batas kirim
            $table->boolean('ship_by_fixed')->default(false)->after('ship_by'); // dari file / diubah manual (tidak dihitung ulang)
        });
    }

    public function down(): void
    {
        Schema::table('marketplace_orders', function (Blueprint $table) {
            $table->dropIndex(['ship_by']);
            $table->dropColumn(['is_preorder', 'ship_by', 'ship_by_fixed']);
        });
        Schema::table('marketplace_products', fn (Blueprint $table) => $table->dropColumn('preorder_days'));
        Schema::table('marketplace_stores', fn (Blueprint $table) => $table->dropColumn('handling_days'));
    }
};
