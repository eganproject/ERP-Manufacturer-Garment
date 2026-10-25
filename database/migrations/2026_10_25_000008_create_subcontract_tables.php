<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Produksi maklon (subkontrak): order ke vendor, kirim/retur bahan, penerimaan hasil, dan tagihan jasa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->boolean('is_subcontractor')->default(false)->after('payment_terms');
        });

        Schema::create('subcontract_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('production_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->date('order_date');
            $table->date('due_date')->nullable();
            $table->json('stages'); // tahap yang dikerjakan vendor, mis. ["sewing","finishing"]
            $table->string('status', 20)->default('open')->index();
            $table->decimal('quantity', 15, 2); // pcs yang dimaklonkan
            $table->decimal('rate', 15, 2)->default(0); // ongkos jasa per pcs bagus
            $table->decimal('received_quantity', 15, 2)->default(0);
            $table->decimal('reject_quantity', 15, 2)->default(0);
            $table->decimal('service_cost', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Bahan yang dikirim ke vendor dan saldonya (dikirim - diretur - terpakai)
        Schema::create('subcontract_order_materials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subcontract_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->constrained()->restrictOnDelete();
            $table->decimal('planned_quantity', 15, 2)->default(0);
            $table->decimal('sent_quantity', 15, 2)->default(0);
            $table->decimal('returned_quantity', 15, 2)->default(0);
            $table->decimal('used_quantity', 15, 2)->default(0); // diisi saat order ditutup
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->timestamps();
        });

        // Surat jalan kirim bahan ke vendor (dispatch) & bukti retur sisa bahan (return)
        Schema::create('subcontract_transfers', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('subcontract_order_id')->constrained()->restrictOnDelete();
            $table->string('type', 20); // dispatch | return
            $table->date('date');
            $table->string('vehicle_number', 30)->nullable();
            $table->string('driver_name')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('subcontract_transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subcontract_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subcontract_order_material_id')->constrained()->restrictOnDelete();
            $table->foreignId('material_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 2);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->timestamps();
        });

        // Penerimaan hasil dari vendor
        Schema::create('subcontract_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('subcontract_order_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->string('vendor_reference', 50)->nullable(); // no. surat jalan vendor
            $table->decimal('good_quantity', 15, 2);
            $table->decimal('reject_quantity', 15, 2)->default(0);
            $table->decimal('rate', 15, 2);
            $table->decimal('amount', 15, 2);
            $table->foreignId('bill_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('production_stages', function (Blueprint $table) {
            $table->foreignId('subcontract_order_id')->nullable()->after('sequence')->constrained()->nullOnDelete();
        });

        Schema::table('production_costs', function (Blueprint $table) {
            $table->foreignId('subcontract_receipt_id')->nullable()->after('production_order_id')->constrained()->nullOnDelete();
        });

        // Tagihan supplier juga bisa berasal dari jasa maklon (tanpa PO)
        Schema::table('bills', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->change();
            $table->foreignId('subcontract_order_id')->nullable()->after('purchase_order_id')->constrained()->restrictOnDelete();
        });

        Schema::table('bill_items', function (Blueprint $table) {
            $table->foreignId('purchase_order_item_id')->nullable()->change();
            $table->foreignId('material_id')->nullable()->change();
            $table->foreignId('subcontract_receipt_id')->nullable()->after('purchase_order_item_id')->constrained()->restrictOnDelete();
            $table->string('description')->nullable()->after('material_id');
        });
    }

    public function down(): void
    {
        Schema::table('bill_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subcontract_receipt_id');
            $table->dropColumn('description');
        });
        Schema::table('bills', fn (Blueprint $table) => $table->dropConstrainedForeignId('subcontract_order_id'));
        Schema::table('production_costs', fn (Blueprint $table) => $table->dropConstrainedForeignId('subcontract_receipt_id'));
        Schema::table('production_stages', fn (Blueprint $table) => $table->dropConstrainedForeignId('subcontract_order_id'));
        Schema::dropIfExists('subcontract_receipts');
        Schema::dropIfExists('subcontract_transfer_items');
        Schema::dropIfExists('subcontract_transfers');
        Schema::dropIfExists('subcontract_order_materials');
        Schema::dropIfExists('subcontract_orders');
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn('is_subcontractor'));
    }
};
