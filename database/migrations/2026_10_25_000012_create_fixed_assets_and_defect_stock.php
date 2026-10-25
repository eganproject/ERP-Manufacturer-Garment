<?php

use App\Support\ChartOfAccounts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Akun baru: persediaan barang cacat, beban barang cacat, laba/rugi pelepasan aset tetap
        if (Schema::hasTable('accounts') && DB::table('accounts')->exists()) {
            ChartOfAccounts::install();
        }

        // ===== Aset tetap =====
        Schema::create('asset_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('depreciation_method', 30)->default('straight_line');
            $table->unsignedInteger('useful_life_months')->default(0); // 0 = tidak disusutkan (mis. tanah)
            $table->decimal('residual_rate', 5, 2)->default(0); // % nilai sisa dari harga perolehan
            $table->foreignId('asset_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('accumulated_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('expense_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('name');
            $table->foreignId('asset_category_id')->constrained()->restrictOnDelete();
            $table->string('serial_number', 100)->nullable();
            $table->string('location', 100)->nullable();
            $table->date('acquisition_date');
            $table->string('acquisition_type', 20); // cash | credit | opening
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('cash_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('bill_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 100)->nullable(); // no. faktur / kuitansi
            $table->decimal('cost', 15, 2);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('residual_value', 15, 2)->default(0);
            $table->string('depreciation_method', 30);
            $table->unsignedInteger('useful_life_months')->default(0);
            $table->date('depreciation_start'); // bulan pertama disusutkan (tanggal 1)
            $table->decimal('opening_accumulated', 15, 2)->default(0); // akumulasi s.d. sebelum depreciation_start
            $table->decimal('accumulated_depreciation', 15, 2)->default(0); // termasuk saldo awal
            $table->foreignId('asset_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('accumulated_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->foreignId('expense_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('status', 20)->default('active')->index();
            $table->date('disposal_date')->nullable();
            $table->string('disposal_type', 20)->nullable(); // sold | scrapped
            $table->decimal('disposal_proceeds', 15, 2)->default(0);
            $table->foreignId('disposal_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->string('disposal_notes')->nullable();
            $table->text('notes')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('depreciation_runs', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->date('period')->index(); // tanggal 1 bulan yang disusutkan
            $table->date('date'); // tanggal jurnal (akhir bulan)
            $table->decimal('total', 15, 2)->default(0);
            $table->unsignedInteger('asset_count')->default(0);
            $table->string('status', 20)->default('posted')->index();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('asset_depreciations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('depreciation_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fixed_asset_id')->constrained()->restrictOnDelete();
            $table->date('period');
            $table->decimal('amount', 15, 2);
            $table->decimal('accumulated', 15, 2); // akumulasi setelah penyusutan ini
            $table->decimal('book_value', 15, 2);
            $table->timestamps();
            $table->unique(['fixed_asset_id', 'period']);
        });

        // ===== Stok barang cacat (karantina) =====
        Schema::table('product_variants', function (Blueprint $table) {
            $table->decimal('defect_stock', 15, 2)->default(0)->after('stock');
            $table->decimal('defect_cost', 15, 2)->default(0)->after('defect_stock');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->string('location', 10)->default('main')->after('type'); // main = stok jual, defect = stok cacat
        });

        Schema::table('sales_return_items', function (Blueprint $table) {
            $table->decimal('defect_quantity', 15, 2)->default(0)->after('quantity');
        });

        Schema::create('defect_handlings', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->date('date');
            $table->string('type', 20); // quarantine | rework | restock | scrap
            $table->string('status', 20)->default('posted')->index();
            $table->decimal('total_cost', 15, 2)->default(0); // nilai barang (HPP)
            $table->decimal('extra_cost', 15, 2)->default(0); // biaya perbaikan
            $table->foreignId('cash_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('defect_handling_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('defect_handling_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 2);
            $table->decimal('unit_cost', 15, 4)->default(0);
            $table->decimal('cost', 15, 2)->default(0);
            $table->decimal('extra_cost', 15, 2)->default(0);
            $table->timestamps();
        });

        $this->seedCategories();
    }

    /** Kategori aset tetap standar (masa manfaat mengikuti kelompok penyusutan pajak). */
    private function seedCategories(): void
    {
        $ids = DB::table('accounts')->pluck('id', 'code');

        if (! isset($ids['1501'], $ids['1509'])) {
            return;
        }

        $now = now();
        $rows = [
            ['TNH', 'Tanah', 'straight_line', 0, '1501', null],
            ['BGN', 'Bangunan Pabrik & Kantor', 'straight_line', 240, '1502', '5202'],
            ['MSN', 'Mesin Jahit & Peralatan Produksi', 'straight_line', 96, '1503', '5202'],
            ['KND', 'Kendaraan', 'declining_balance', 96, '1504', '6107'],
            ['INV', 'Inventaris & Peralatan Kantor', 'straight_line', 48, '1505', '6107'],
        ];

        foreach ($rows as [$code, $name, $method, $life, $asset, $expense]) {
            DB::table('asset_categories')->insert([
                'code' => $code,
                'name' => $name,
                'depreciation_method' => $method,
                'useful_life_months' => $life,
                'residual_rate' => 0,
                'asset_account_id' => $ids[$asset],
                'accumulated_account_id' => $life > 0 ? $ids['1509'] : null,
                'expense_account_id' => $expense ? ($ids[$expense] ?? null) : null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('defect_handling_items');
        Schema::dropIfExists('defect_handlings');
        Schema::table('sales_return_items', fn (Blueprint $table) => $table->dropColumn('defect_quantity'));
        Schema::table('stock_movements', fn (Blueprint $table) => $table->dropColumn('location'));
        Schema::table('product_variants', fn (Blueprint $table) => $table->dropColumn(['defect_stock', 'defect_cost']));
        Schema::dropIfExists('asset_depreciations');
        Schema::dropIfExists('depreciation_runs');
        Schema::dropIfExists('fixed_assets');
        Schema::dropIfExists('asset_categories');
    }
};
