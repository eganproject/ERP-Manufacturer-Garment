<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Termin pembayaran default (hari) untuk menghitung jatuh tempo
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedSmallInteger('payment_terms')->default(30)->after('tax_number');
        });
        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedSmallInteger('payment_terms')->default(30)->after('tax_number');
        });

        // Jumlah yang sudah ditagihkan agar tidak ditagih dua kali
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->decimal('invoiced_quantity', 15, 2)->default(0)->after('delivered_quantity');
        });
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('billed_quantity', 15, 2)->default(0)->after('received_quantity');
        });

        // Invoice penjualan (piutang / AR)
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_id')->constrained()->restrictOnDelete();
            $table->date('invoice_date');
            $table->date('due_date')->index();
            $table->string('status', 20)->default('unpaid')->index();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 2);
            $table->decimal('price', 15, 2);
            $table->decimal('subtotal', 15, 2);
            $table->timestamps();
        });

        // Tagihan supplier (hutang / AP)
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('supplier_invoice_number', 50)->nullable();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->date('bill_date');
            $table->date('due_date')->index();
            $table->string('status', 20)->default('unpaid')->index();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);
            $table->decimal('paid_amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('bill_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('material_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 2);
            $table->decimal('price', 15, 2);
            $table->decimal('subtotal', 15, 2);
            $table->timestamps();
        });

        // Penerimaan dari pelanggan (RV) & pembayaran ke supplier (PV)
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('type', 20)->index();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->date('payment_date');
            $table->string('method', 20);
            $table->string('reference', 100)->nullable();
            $table->decimal('amount', 15, 2);
            $table->string('status', 20)->default('posted')->index();
            $table->text('notes')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->morphs('payable');
            $table->decimal('amount', 15, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('bill_items');
        Schema::dropIfExists('bills');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');

        Schema::table('purchase_order_items', fn (Blueprint $table) => $table->dropColumn('billed_quantity'));
        Schema::table('sales_order_items', fn (Blueprint $table) => $table->dropColumn('invoiced_quantity'));
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn('payment_terms'));
        Schema::table('customers', fn (Blueprint $table) => $table->dropColumn('payment_terms'));
    }
};
