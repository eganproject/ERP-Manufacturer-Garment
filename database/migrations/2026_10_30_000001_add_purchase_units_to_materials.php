<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Satuan beli vs satuan stok: bahan boleh dibeli dalam satuan lain (mis. YD) yang dikonversi ke
 * satuan stok (mis. M). Baris PO menyimpan salinan satuan & faktor konversinya.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->foreignId('purchase_unit_id')->nullable()->after('unit_id')->constrained('units')->nullOnDelete();
            // Jumlah satuan stok dalam 1 satuan beli (1 YD = 0,9144 M)
            $table->decimal('purchase_conversion', 15, 6)->default(1)->after('purchase_unit_id');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->after('material_id')->constrained('units')->nullOnDelete();
            $table->decimal('conversion', 15, 6)->default(1)->after('unit_id');
        });

        Schema::table('goods_receipt_items', function (Blueprint $table) {
            // Jumlah dalam satuan stok yang masuk ke kartu stok
            $table->decimal('stock_quantity', 15, 2)->nullable()->after('quantity');
        });

        DB::table('purchase_order_items')->update([
            'unit_id' => DB::raw('(select unit_id from materials where materials.id = purchase_order_items.material_id)'),
        ]);
        DB::table('goods_receipt_items')->update(['stock_quantity' => DB::raw('quantity')]);
    }

    public function down(): void
    {
        Schema::table('goods_receipt_items', fn (Blueprint $table) => $table->dropColumn('stock_quantity'));
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_id');
            $table->dropColumn('conversion');
        });
        Schema::table('materials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_unit_id');
            $table->dropColumn('purchase_conversion');
        });
    }
};
