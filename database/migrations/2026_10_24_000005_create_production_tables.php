<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('bom_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_id')->nullable()->constrained()->nullOnDelete();
            $table->date('start_date');
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->decimal('quantity', 15, 2)->default(0);
            $table->decimal('finished_quantity', 15, 2)->default(0);
            $table->decimal('reject_quantity', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('materials_issued_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Rincian jumlah per varian (ukuran/warna)
        Schema::create('production_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 2);
            $table->decimal('finished_quantity', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('production_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->constrained()->restrictOnDelete();
            $table->decimal('required_quantity', 15, 2);
            $table->decimal('issued_quantity', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('production_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 20); // cutting | sewing | finishing | qc | packing
            $table->unsignedTinyInteger('sequence');
            $table->string('status', 20)->default('pending');
            $table->decimal('output_quantity', 15, 2)->default(0);
            $table->decimal('reject_quantity', 15, 2)->default(0);
            $table->string('pic')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_stages');
        Schema::dropIfExists('production_materials');
        Schema::dropIfExists('production_order_items');
        Schema::dropIfExists('production_orders');
    }
};
