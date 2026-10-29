<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baris BOM bisa berlaku hanya untuk ukuran / warna tertentu (mis. kain hitam hanya untuk varian Hitam,
 * pemakaian kain XL lebih banyak). Kosong = berlaku untuk semua varian.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bom_items', function (Blueprint $table) {
            $table->json('sizes')->nullable()->after('waste_percent');
            $table->json('colors')->nullable()->after('sizes');
        });
    }

    public function down(): void
    {
        Schema::table('bom_items', fn (Blueprint $table) => $table->dropColumn(['sizes', 'colors']));
    }
};
