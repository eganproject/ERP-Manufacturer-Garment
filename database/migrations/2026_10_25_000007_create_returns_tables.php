<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retur penjualan (nota kredit, mengurangi piutang) dan retur pembelian (nota debit, mengurangi hutang).
 */
return new class extends Migration
{
    public function up(): void
    {
        // returned_quantity = total diretur; returned_*_quantity = bagian retur yang sudah ditagih (dikreditkan)
        Schema::table('sales_order_items', function (Blueprint $table) {
            $table->decimal('returned_quantity', 15, 2)->default(0)->after('invoiced_quantity');
            $table->decimal('returned_invoiced_quantity', 15, 2)->default(0)->after('returned_quantity');
        });
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->decimal('returned_quantity', 15, 2)->default(0)->after('billed_quantity');
            $table->decimal('returned_billed_quantity', 15, 2)->default(0)->after('returned_quantity');
        });

        // Bagian invoice / tagihan yang dilunasi dengan nota kredit / debit
        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('credited_amount', 15, 2)->default(0)->after('paid_amount');
        });
        Schema::table('bills', function (Blueprint $table) {
            $table->decimal('credited_amount', 15, 2)->default(0)->after('paid_amount');
        });

        Schema::create('sales_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('sales_order_id')->constrained()->restrictOnDelete();
            $table->date('return_date');
            $table->string('status', 20)->default('posted')->index();
            $table->string('reason', 255);
            $table->text('notes')->nullable();
            $this->noteColumns($table);
            $table->timestamps();
        });

        Schema::create('sales_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $this->lineColumns($table);
            $table->timestamps();
        });

        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->date('return_date');
            $table->string('status', 20)->default('posted')->index();
            $table->string('reason', 255);
            $table->text('notes')->nullable();
            $this->noteColumns($table);
            $table->timestamps();
        });

        Schema::create('purchase_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('material_id')->constrained()->restrictOnDelete();
            $this->lineColumns($table);
            $table->timestamps();
        });

        // Nota kredit/debit yang dipakai untuk mengurangi sisa invoice / tagihan
        Schema::create('credit_applications', function (Blueprint $table) {
            $table->id();
            $table->morphs('source');
            $table->morphs('payable');
            $table->date('applied_date');
            $table->decimal('amount', 15, 2);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    private function noteColumns(Blueprint $table): void
    {
        // Nilai nota = barang retur yang sudah ditagih (DPP) + PPN
        $table->decimal('amount', 15, 2)->default(0);
        $table->decimal('tax_rate', 5, 2)->default(0);
        $table->decimal('tax_amount', 15, 2)->default(0);
        $table->decimal('total', 15, 2)->default(0);
        $table->decimal('applied_amount', 15, 2)->default(0);
        $table->decimal('refunded_amount', 15, 2)->default(0);
        $table->date('refund_date')->nullable();
        $table->string('refund_method', 20)->nullable();
        $table->string('refund_reference', 100)->nullable();
        $table->text('cancel_reason')->nullable();
        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
    }

    private function lineColumns(Blueprint $table): void
    {
        $table->decimal('quantity', 15, 2);
        // Bagian qty yang sudah ditagih sehingga menjadi nota kredit/debit
        $table->decimal('billed_quantity', 15, 2)->default(0);
        $table->decimal('price', 15, 2);
        $table->decimal('subtotal', 15, 2);
        $table->decimal('amount', 15, 2)->default(0);
        $table->decimal('unit_cost', 15, 4)->default(0);
        $table->decimal('cost', 15, 2)->default(0);
        $table->decimal('billed_cost', 15, 2)->default(0);
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_applications');
        Schema::dropIfExists('purchase_return_items');
        Schema::dropIfExists('purchase_returns');
        Schema::dropIfExists('sales_return_items');
        Schema::dropIfExists('sales_returns');

        Schema::table('bills', fn (Blueprint $table) => $table->dropColumn('credited_amount'));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('credited_amount'));
        Schema::table('purchase_order_items', fn (Blueprint $table) => $table->dropColumn(['returned_quantity', 'returned_billed_quantity']));
        Schema::table('sales_order_items', fn (Blueprint $table) => $table->dropColumn(['returned_quantity', 'returned_invoiced_quantity']));
    }
};
