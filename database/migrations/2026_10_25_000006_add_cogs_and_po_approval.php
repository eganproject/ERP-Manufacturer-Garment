<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HPP penjualan (harga pokok barang terkirim & tertagih) dan persetujuan PO.
 */
return new class extends Migration
{
    public function up(): void
    {
        // HPP per pcs saat barang keluar (harga pokok rata-rata varian pada tanggal kirim)
        Schema::table('delivery_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 2)->default(0)->after('quantity');
            $table->decimal('cost', 15, 2)->default(0)->after('unit_cost');
        });

        // HPP yang diakui bersama pendapatan invoice
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 2)->default(0)->after('subtotal');
            $table->decimal('cost', 15, 2)->default(0)->after('unit_cost');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->timestamp('submitted_at')->nullable()->after('status');
            $table->foreignId('approved_by')->nullable()->after('submitted_at')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('approval_notes')->nullable()->after('approved_at');
        });

        $this->backfillCosts();
    }

    /** Isi HPP data lama dari mutasi stok surat jalan, lalu rata-rata per baris SO untuk invoice. */
    private function backfillCosts(): void
    {
        DB::table('delivery_items')->orderBy('id')->get()->each(function ($item) {
            $unitCost = (float) DB::table('stock_movements')
                ->where('reference_type', 'delivery')
                ->where('reference_id', $item->delivery_id)
                ->where('stockable_type', 'product_variant')
                ->where('stockable_id', $item->product_variant_id)
                ->value('unit_cost');

            DB::table('delivery_items')->where('id', $item->id)->update([
                'unit_cost' => round($unitCost, 2),
                'cost' => round($unitCost * (float) $item->quantity, 2),
            ]);
        });

        DB::table('invoice_items')->orderBy('id')->get()->each(function ($item) {
            $delivered = DB::table('delivery_items')->where('sales_order_item_id', $item->sales_order_item_id)
                ->selectRaw('COALESCE(SUM(quantity), 0) as qty, COALESCE(SUM(cost), 0) as cost')->first();
            $unitCost = (float) $delivered->qty > 0 ? (float) $delivered->cost / (float) $delivered->qty : 0;

            DB::table('invoice_items')->where('id', $item->id)->update([
                'unit_cost' => round($unitCost, 2),
                'cost' => round($unitCost * (float) $item->quantity, 2),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['submitted_at', 'approved_at', 'approval_notes']);
        });
        Schema::table('invoice_items', fn (Blueprint $table) => $table->dropColumn(['unit_cost', 'cost']));
        Schema::table('delivery_items', fn (Blueprint $table) => $table->dropColumn(['unit_cost', 'cost']));
    }
};
