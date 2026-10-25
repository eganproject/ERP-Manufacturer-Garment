<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HPP produksi (harga pokok rata-rata, biaya CMT/upah/overhead) dan MRP (supplier utama bahan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->foreignId('default_supplier_id')->nullable()->after('unit_id')->constrained('suppliers')->nullOnDelete();
            $table->decimal('average_cost', 15, 4)->default(0)->after('price');
        });

        // Saldo awal harga pokok rata-rata = harga beli standar
        DB::table('materials')->update(['average_cost' => DB::raw('price')]);

        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('average_cost', 15, 2)->default(0)->after('price');
        });

        Schema::table('boms', function (Blueprint $table) {
            $table->decimal('labor_cost', 15, 2)->default(0)->after('is_active'); // upah jahit / CMT per pcs
            $table->decimal('overhead_cost', 15, 2)->default(0)->after('labor_cost'); // overhead per pcs
        });

        Schema::table('production_materials', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 4)->default(0)->after('issued_quantity');
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->decimal('material_cost', 15, 2)->default(0)->after('reject_quantity');
            $table->decimal('conversion_cost', 15, 2)->default(0)->after('material_cost');
            $table->decimal('total_cost', 15, 2)->default(0)->after('conversion_cost');
            $table->decimal('unit_cost', 15, 2)->default(0)->after('total_cost');
        });

        Schema::create('production_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20); // cmt | labor | overhead | other
            $table->string('description');
            $table->decimal('amount', 15, 2);
            $table->date('date');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 4)->nullable()->after('balance');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropColumn('unit_cost'));
        Schema::dropIfExists('production_costs');
        Schema::table('production_orders', fn (Blueprint $table) => $table->dropColumn(['material_cost', 'conversion_cost', 'total_cost', 'unit_cost']));
        Schema::table('production_materials', fn (Blueprint $table) => $table->dropColumn('unit_cost'));
        Schema::table('boms', fn (Blueprint $table) => $table->dropColumn(['labor_cost', 'overhead_cost']));
        Schema::table('product_variants', fn (Blueprint $table) => $table->dropColumn('average_cost'));
        Schema::table('materials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_supplier_id');
            $table->dropColumn('average_cost');
        });
    }
};
