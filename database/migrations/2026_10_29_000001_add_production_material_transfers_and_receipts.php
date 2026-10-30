<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Produksi internal yang lebih lengkap:
 * - bahan tambahan (BBT-) & retur sisa bahan ke gudang (RSB-) selama SPK berjalan;
 * - setor hasil produksi bertahap (HPJ-), termasuk barang reject grade B yang masuk stok cacat.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Di MySQL DDL tidak transaksional: percobaan lama yang gagal (nama FK > 64 karakter)
        // bisa meninggalkan tabel setengah jadi. Migrasi ini belum tercatat, jadi tabelnya pasti kosong.
        Schema::dropIfExists('production_material_transfer_items');
        Schema::dropIfExists('production_material_transfers');

        Schema::create('production_material_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->string('type', 10); // issue | return
            $table->date('date');
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('production_material_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_material_transfer_id')
                ->constrained(indexName: 'pmt_items_transfer_id_foreign')->cascadeOnDelete();
            $table->foreignId('material_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 2);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->timestamps();
        });

        Schema::table('production_materials', function (Blueprint $table) {
            $table->decimal('returned_quantity', 15, 2)->default(0)->after('issued_quantity'); // retur sisa bahan internal ke gudang
        });

        Schema::create('production_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_final')->default(false); // setoran penutup saat SPK diselesaikan
            $table->decimal('quantity', 15, 2)->default(0); // pcs bagus → stok jual
            $table->decimal('defect_quantity', 15, 2)->default(0); // pcs reject grade B → stok cacat
            $table->decimal('unit_cost', 15, 2)->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('production_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 2)->default(0);
            $table->decimal('defect_quantity', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::table('production_order_items', function (Blueprint $table) {
            $table->decimal('defect_quantity', 15, 2)->default(0)->after('finished_quantity');
        });

        Schema::table('production_orders', function (Blueprint $table) {
            $table->decimal('defect_quantity', 15, 2)->default(0)->after('reject_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('production_orders', fn (Blueprint $table) => $table->dropColumn('defect_quantity'));
        Schema::table('production_order_items', fn (Blueprint $table) => $table->dropColumn('defect_quantity'));
        Schema::dropIfExists('production_receipt_items');
        Schema::dropIfExists('production_receipts');
        Schema::table('production_materials', fn (Blueprint $table) => $table->dropColumn('returned_quantity'));
        Schema::dropIfExists('production_material_transfer_items');
        Schema::dropIfExists('production_material_transfers');
    }
};
