<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Dokumen yang menggerakkan stok dan kini mencatat gudangnya. */
    private const DOCUMENTS = ['goods_receipts', 'deliveries', 'production_orders', 'subcontract_transfers', 'sales_returns', 'purchase_returns', 'defect_handlings'];

    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('address', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        $now = now();
        $defaultId = DB::table('warehouses')->insertGetId([
            'code' => 'GU', 'name' => 'Gudang Utama', 'is_active' => true, 'is_default' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);

        // Saldo stok per gudang (stok jual & stok cacat). Total per barang tetap di materials/product_variants.
        Schema::create('warehouse_stocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->morphs('stockable');
            $table->decimal('quantity', 15, 2)->default(0);
            $table->decimal('defect_quantity', 15, 2)->default(0);
            $table->timestamps();
            $table->unique(['warehouse_id', 'stockable_type', 'stockable_id']);
        });

        foreach (['material' => 'materials', 'product_variant' => 'product_variants'] as $type => $table) {
            $hasDefect = $table === 'product_variants';
            $rows = DB::table($table)->where(fn ($q) => $hasDefect ? $q->where('stock', '!=', 0)->orWhere('defect_stock', '!=', 0) : $q->where('stock', '!=', 0))->get();

            foreach ($rows as $row) {
                DB::table('warehouse_stocks')->insert([
                    'warehouse_id' => $defaultId, 'stockable_type' => $type, 'stockable_id' => $row->id,
                    'quantity' => $row->stock, 'defect_quantity' => $hasDefect ? $row->defect_stock : 0,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->after('stockable_id')->constrained()->restrictOnDelete();
            $table->decimal('warehouse_balance', 15, 2)->nullable()->after('balance');
        });
        DB::table('stock_movements')->update(['warehouse_id' => $defaultId, 'warehouse_balance' => DB::raw('balance')]);

        foreach (self::DOCUMENTS as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('warehouse_id')->nullable()->constrained()->restrictOnDelete();
            });
            DB::table($name)->update(['warehouse_id' => $defaultId]);
        }

        // Transfer antar gudang (TG-)
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->date('date');
            $table->foreignId('from_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->foreignId('to_warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('status', 20)->default('posted')->index();
            $table->text('notes')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->morphs('stockable');
            $table->decimal('quantity', 15, 2);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->timestamps();
        });

        // Stock opname per gudang (OPN-): draft hitung fisik → posting selisih sebagai penyesuaian
        Schema::create('stock_opnames', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('scope', 20); // materials | products
            $table->string('status', 20)->default('draft')->index();
            $table->text('notes')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stock_opname_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_opname_id')->constrained()->cascadeOnDelete();
            $table->morphs('stockable');
            $table->decimal('system_quantity', 15, 2)->default(0);
            $table->decimal('counted_quantity', 15, 2)->nullable();
            $table->decimal('difference', 15, 2)->default(0);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('value', 15, 2)->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_items');
        Schema::dropIfExists('stock_opnames');
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');

        foreach (self::DOCUMENTS as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('warehouse_id'));
        }

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
            $table->dropColumn('warehouse_balance');
        });

        Schema::dropIfExists('warehouse_stocks');
        Schema::dropIfExists('warehouses');
    }
};
