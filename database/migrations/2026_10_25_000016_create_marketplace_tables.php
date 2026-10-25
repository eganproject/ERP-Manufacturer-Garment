<?php

use App\Support\ChartOfAccounts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penjualan marketplace (TikTok Shop & Shopee): toko, pemetaan SKU, pesanan, retur & pencairan dana.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Akun baru: saldo penjual, piutang marketplace, penjualan, voucher, biaya platform, ongkir, selisih pencairan
        if (Schema::hasTable('accounts') && DB::table('accounts')->exists()) {
            ChartOfAccounts::install();
        }
        DB::table('accounts')->where('code', '1110')->update(['is_cash' => true]);

        Schema::create('marketplace_stores', function (Blueprint $table) {
            $table->id();
            $table->string('platform', 20)->index(); // shopee | tiktok
            $table->string('name');
            $table->string('shop_id', 60)->nullable(); // ID toko di platform
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete(); // gudang sumber stok
            $table->foreignId('balance_account_id')->nullable()->constrained('accounts')->nullOnDelete(); // saldo penjual (kas/bank)
            $table->decimal('tax_rate', 5, 2)->default(0); // PPN termasuk dalam harga jual (PKP)
            $table->boolean('is_active')->default(true);
            // Integrasi API resmi (opsional)
            $table->boolean('api_enabled')->default(false);
            $table->string('api_partner_id', 100)->nullable(); // Shopee partner_id / TikTok app_key
            $table->text('api_partner_key')->nullable(); // terenkripsi
            $table->text('api_access_token')->nullable(); // terenkripsi
            $table->text('api_refresh_token')->nullable(); // terenkripsi
            $table->string('api_shop_cipher', 120)->nullable(); // TikTok shop_cipher
            $table->timestamp('api_token_expires_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Pemetaan SKU marketplace → varian ERP (isi per paket = bundling, mis. 1 SKU = 3 pcs)
        Schema::create('marketplace_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_store_id')->constrained()->cascadeOnDelete();
            $table->string('marketplace_sku', 100);
            $table->string('marketplace_name')->nullable();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('units_per_item', 10, 2)->default(1);
            $table->timestamps();
            $table->unique(['marketplace_store_id', 'marketplace_sku']);
        });

        Schema::create('marketplace_settlements', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('marketplace_store_id')->constrained()->restrictOnDelete();
            $table->date('date'); // tanggal dana dilepas ke saldo penjual
            $table->string('status', 20)->default('draft')->index();
            $table->string('source_file')->nullable();
            $table->decimal('order_amount', 15, 2)->default(0);
            $table->decimal('voucher', 15, 2)->default(0);
            $table->decimal('fees', 15, 2)->default(0); // komisi + layanan + transaksi + lain
            $table->decimal('shipping_fee', 15, 2)->default(0);
            $table->decimal('adjustment', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2)->default(0);
            $table->decimal('receivable', 15, 2)->default(0); // piutang pesanan yang dilunasi
            $table->text('notes')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('marketplace_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_store_id')->constrained()->restrictOnDelete();
            $table->string('order_number', 60);
            $table->string('status', 30)->index();
            $table->string('platform_status', 100)->nullable();
            $table->dateTime('order_date');
            $table->dateTime('paid_at')->nullable();
            $table->date('shipped_at')->nullable()->index(); // stok keluar & penjualan diakui
            $table->date('completed_at')->nullable();
            $table->date('cancelled_at')->nullable();
            $table->date('returned_at')->nullable(); // retur diterima
            $table->string('buyer_username')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('city')->nullable();
            $table->string('courier')->nullable();
            $table->string('tracking_number', 100)->nullable();
            $table->decimal('buyer_shipping_fee', 15, 2)->default(0); // informasi
            $table->decimal('total', 15, 2)->default(0); // harga produk setelah diskon penjual (termasuk PPN)
            $table->decimal('dpp', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('cost', 15, 2)->default(0); // HPP saat dikirim
            $table->decimal('returned_total', 15, 2)->default(0);
            $table->decimal('returned_dpp', 15, 2)->default(0);
            $table->decimal('returned_tax', 15, 2)->default(0);
            $table->decimal('returned_cost', 15, 2)->default(0); // HPP barang yang kembali ke stok
            $table->string('return_condition', 30)->nullable(); // kondisi paket retur (lihat MarketplaceReturnCondition)
            $table->decimal('returned_lost_cost', 15, 2)->default(0); // HPP barang direfund tapi tidak kembali / bukan barang kita
            // Klaim / banding ke platform atas retur bermasalah (barang tertukar, tidak kembali, rusak oleh pembeli)
            $table->string('claim_status', 20)->nullable(); // open | won | lost
            $table->decimal('claim_amount', 15, 2)->default(0); // kompensasi disetujui platform
            $table->date('claim_resolved_at')->nullable();
            $table->text('claim_notes')->nullable();
            $table->decimal('settled_receivable', 15, 2)->default(0); // piutang yang sudah dilunasi pencairan
            $table->decimal('settled_net', 15, 2)->default(0); // dana bersih diterima
            $table->string('cancel_reason')->nullable();
            $table->string('return_reason')->nullable();
            $table->text('notes')->nullable();
            $table->string('source', 20)->default('import'); // import | api | manual
            $table->timestamps();
            $table->unique(['marketplace_store_id', 'order_number']);
        });

        Schema::create('marketplace_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_order_id')->constrained()->cascadeOnDelete();
            $table->string('marketplace_sku', 100)->nullable();
            $table->string('product_name')->nullable();
            $table->string('variation')->nullable();
            $table->foreignId('product_variant_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('units_per_item', 10, 2)->default(1);
            $table->decimal('quantity', 15, 2);
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('dpp', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('unit_cost', 15, 2)->default(0); // HPP per pcs ERP saat kirim
            $table->decimal('cost', 15, 2)->default(0);
            $table->decimal('returned_quantity', 15, 2)->default(0); // qty direfund (penjualan dibalik)
            $table->decimal('restocked_quantity', 15, 2)->default(0); // qty barang kembali ke gudang
            $table->decimal('defect_quantity', 15, 2)->default(0); // dari yang kembali, masuk stok cacat
            $table->timestamps();
        });

        Schema::create('marketplace_settlement_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketplace_settlement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketplace_order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_number', 60)->nullable();
            $table->string('description')->nullable();
            $table->decimal('order_amount', 15, 2)->default(0);
            $table->decimal('voucher', 15, 2)->default(0);
            $table->decimal('commission', 15, 2)->default(0);
            $table->decimal('service_fee', 15, 2)->default(0);
            $table->decimal('transaction_fee', 15, 2)->default(0);
            $table->decimal('shipping_fee', 15, 2)->default(0);
            $table->decimal('other_fee', 15, 2)->default(0);
            $table->decimal('adjustment', 15, 2)->default(0);
            $table->decimal('net_amount', 15, 2)->default(0);
            $table->decimal('receivable', 15, 2)->default(0); // piutang pesanan yang ditutup (diisi saat posting)
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_settlement_lines');
        Schema::dropIfExists('marketplace_order_items');
        Schema::dropIfExists('marketplace_orders');
        Schema::dropIfExists('marketplace_settlements');
        Schema::dropIfExists('marketplace_products');
        Schema::dropIfExists('marketplace_stores');
    }
};
