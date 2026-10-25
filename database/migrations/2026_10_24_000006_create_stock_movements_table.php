<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->morphs('stockable'); // Material | ProductVariant
            $table->date('date');
            $table->string('type', 30); // purchase_receipt, production_issue, production_output, adjustment, sales_delivery
            $table->decimal('quantity', 15, 2); // + masuk, - keluar
            $table->decimal('balance', 15, 2);
            $table->nullableMorphs('reference');
            $table->string('reference_number', 30)->nullable();
            $table->string('notes')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
