<?php

use App\Support\ChartOfAccounts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bagan akun (COA)
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('type', 20)->index();
            $table->string('normal_balance', 6);
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->restrictOnDelete();
            $table->boolean('is_header')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false);
            $table->string('description')->nullable();
            $table->timestamps();
        });

        // Jurnal umum: otomatis dari transaksi (JO-) atau manual (JU-)
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->date('date')->index();
            $table->string('type', 10)->index();
            $table->string('description');
            $table->nullableMorphs('source');
            $table->string('source_number', 50)->nullable();
            $table->string('event', 40)->nullable();
            $table->decimal('total', 15, 2)->default(0);
            $table->foreignId('reversal_of_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->string('description')->nullable();
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            // Rincian pembantu: pelanggan/supplier (piutang-hutang), SPK (WIP), order maklon (bahan di vendor)
            $table->nullableMorphs('reference');
            $table->index(['account_id', 'journal_entry_id']);
        });

        // Tutup buku per bulan
        Schema::create('accounting_periods', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['year', 'month']);
        });

        ChartOfAccounts::install();
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_periods');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('journal_entries');
        Schema::dropIfExists('accounts');
    }
};
