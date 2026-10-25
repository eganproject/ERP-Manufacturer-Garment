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
        // Akun baru: Hutang PPN & PPN Lebih Bayar (kompensasi ke masa berikutnya)
        if (Schema::hasTable('accounts') && DB::table('accounts')->exists()) {
            ChartOfAccounts::install();
        }

        // Nomor faktur pajak keluaran (invoice) & masukan (tagihan supplier, aset tunai)
        foreach (['invoices', 'bills'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('tax_invoice_number', 30)->nullable()->index();
                $table->date('tax_invoice_date')->nullable();
            });
        }

        Schema::table('fixed_assets', function (Blueprint $table) {
            $table->string('tax_invoice_number', 30)->nullable();
        });

        // Penutupan masa PPN (PPN-): PPN keluaran − masukan − kompensasi = kurang / lebih bayar
        Schema::create('vat_periods', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->date('period')->index(); // tanggal 1 masa pajak
            $table->date('date'); // tanggal jurnal (akhir bulan)
            $table->decimal('output_tax', 15, 2)->default(0); // PPN keluaran bersih retur
            $table->decimal('input_tax', 15, 2)->default(0); // PPN masukan bersih retur
            $table->decimal('compensation', 15, 2)->default(0); // lebih bayar masa sebelumnya yang dikompensasikan
            $table->decimal('net', 15, 2)->default(0); // + kurang bayar (disetor), − lebih bayar (dikompensasikan)
            $table->string('status', 20)->default('posted')->index();
            $table->text('notes')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_periods');

        Schema::table('fixed_assets', fn (Blueprint $table) => $table->dropColumn('tax_invoice_number'));

        foreach (['invoices', 'bills'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropIndex(['tax_invoice_number']);
                $table->dropColumn(['tax_invoice_number', 'tax_invoice_date']);
            });
        }
    }
};
