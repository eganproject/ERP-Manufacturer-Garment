<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Kas & bank (banyak rekening, kas masuk/keluar, transfer antar rekening), klasifikasi arus kas per akun,
 * dan anggaran per akun per bulan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('is_cash')->default(false)->after('is_system');
            $table->string('bank_name')->nullable()->after('is_cash');
            $table->string('bank_account_number', 50)->nullable()->after('bank_name');
            $table->string('bank_account_holder')->nullable()->after('bank_account_number');
            // operating | investing | financing (null = otomatis menurut kelompok akun)
            $table->string('cash_flow_activity', 12)->nullable()->after('bank_account_holder');
        });

        DB::table('accounts')->whereIn('code', ['1101', '1102'])->update(['is_cash' => true]);

        // Rekening kas/bank yang dipakai transaksi (kosong = akun default menurut metode bayar)
        foreach (['payments', 'employee_advances', 'payrolls'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('cash_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            });
        }
        foreach (['sales_returns', 'purchase_returns'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('refund_account_id')->nullable()->constrained('accounts')->nullOnDelete();
            });
        }

        // Bukti kas masuk (KM-), kas keluar (KK-), transfer antar rekening (TRF-)
        Schema::create('cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->string('type', 10)->index();
            $table->date('date')->index();
            $table->foreignId('cash_account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('to_account_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->decimal('amount', 15, 2);
            $table->string('party_name')->nullable();
            $table->string('reference', 100)->nullable();
            $table->string('description');
            $table->string('status', 20)->default('posted');
            $table->string('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('cash_transaction_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('description')->nullable();
            $table->decimal('amount', 15, 2);
        });

        // Anggaran tahunan per akun per bulan
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedSmallInteger('year')->index();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->unsignedTinyInteger('month');
            $table->decimal('amount', 15, 2)->default(0);
            $table->unique(['budget_id', 'account_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_lines');
        Schema::dropIfExists('budgets');
        Schema::dropIfExists('cash_transaction_lines');
        Schema::dropIfExists('cash_transactions');

        foreach (['sales_returns', 'purchase_returns'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('refund_account_id'));
        }
        foreach (['payments', 'employee_advances', 'payrolls'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('cash_account_id'));
        }

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['is_cash', 'bank_name', 'bank_account_number', 'bank_account_holder', 'cash_flow_activity']);
        });
    }
};
