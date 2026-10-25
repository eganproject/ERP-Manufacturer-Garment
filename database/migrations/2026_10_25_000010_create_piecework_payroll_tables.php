<?php

use App\Support\ChartOfAccounts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upah borongan karyawan produksi: master karyawan, tarif per operasi/segmen & per pcs jadi,
 * hasil kerja harian per SPK, penggajian per periode (mingguan/custom), dan kasbon.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('work_type', 20)->default('operation'); // full | operation | mixed
            $table->string('nik', 30)->nullable();
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->string('bank_account')->nullable();
            $table->date('join_date')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Tarif borongan 1 pcs jadi (dikerjakan satu orang dari potong sampai selesai)
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('full_garment_rate', 15, 2)->default(0)->after('price');
        });

        // Daftar operasi / segmen per artikel beserta tarif per pcs
        Schema::create('product_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('stage', 20)->default('sewing');
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->decimal('rate', 15, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('payrolls', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('draft')->index();
            $table->decimal('total_gross', 15, 2)->default(0);
            $table->decimal('total_allowance', 15, 2)->default(0);
            $table->decimal('total_deduction', 15, 2)->default(0);
            $table->decimal('total_net', 15, 2)->default(0);
            $table->date('payment_date')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Slip upah per karyawan dalam satu periode
        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 2)->default(0);
            $table->decimal('gross', 15, 2)->default(0);
            $table->decimal('allowance', 15, 2)->default(0);
            $table->decimal('advance_deduction', 15, 2)->default(0);
            $table->decimal('other_deduction', 15, 2)->default(0);
            $table->decimal('net', 15, 2)->default(0);
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        // Lembar hasil kerja harian per SPK (HKB-)
        Schema::create('piecework_sheets', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('production_order_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('piecework_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('piecework_sheet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_operation_id')->nullable()->constrained()->restrictOnDelete(); // null = 1 pcs jadi
            $table->string('operation_name');
            $table->decimal('quantity', 15, 2); // hasil bagus (dibayar)
            $table->decimal('reject_quantity', 15, 2)->default(0); // reject QC (tidak dibayar)
            $table->decimal('rate', 15, 2);
            $table->decimal('amount', 15, 2);
            $table->foreignId('payroll_line_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('employee_advances', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->date('date');
            $table->decimal('amount', 15, 2);
            $table->string('payment_method', 20)->default('cash');
            $table->string('status', 20)->default('posted');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::table('production_costs', function (Blueprint $table) {
            $table->foreignId('piecework_sheet_id')->nullable()->after('subcontract_receipt_id')->constrained()->nullOnDelete();
        });

        // Akun baru: piutang karyawan (kasbon) & bonus/tunjangan tenaga kerja produksi
        ChartOfAccounts::install();
    }

    public function down(): void
    {
        Schema::table('production_costs', fn (Blueprint $table) => $table->dropConstrainedForeignId('piecework_sheet_id'));
        Schema::dropIfExists('employee_advances');
        Schema::dropIfExists('piecework_entries');
        Schema::dropIfExists('piecework_sheets');
        Schema::dropIfExists('payroll_lines');
        Schema::dropIfExists('payrolls');
        Schema::dropIfExists('product_operations');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('full_garment_rate'));
        Schema::dropIfExists('employees');
    }
};
