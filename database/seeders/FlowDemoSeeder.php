<?php

namespace Database\Seeders;

use App\Enums\CashTransactionType;
use App\Enums\DefectHandlingType;
use App\Enums\PaymentType;
use App\Enums\ProductionOrderStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\StageStatus;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\AssetCategory;
use App\Models\Bom;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\BillService;
use App\Services\CashBankService;
use App\Services\DefectService;
use App\Services\DepreciationService;
use App\Services\DocumentNumber;
use App\Services\FixedAssetService;
use App\Services\InvoiceService;
use App\Services\PaymentService;
use App\Services\PayrollService;
use App\Services\PieceworkService;
use App\Services\ProductionService;
use App\Services\PurchaseService;
use App\Services\QuotationService;
use App\Services\ReturnService;
use App\Services\SalesService;
use App\Services\SubcontractService;
use App\Services\VatService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Data demo satu siklus penuh (± 5 minggu terakhir) lewat service yang sama dengan aplikasi:
 * SO → SPK (internal & maklon) → PO + persetujuan → penerimaan → tagihan → upah borongan → penggajian
 * → surat jalan → invoice → retur (sebagian cacat → perbaikan) → pembayaran → kas & bank → aset tetap
 * & penyusutan. Beberapa dokumen sengaja dibiarkan terbuka
 * supaya panel "Perlu Ditindaklanjuti" di dashboard terisi.
 *
 * Jalankan: php artisan db:seed --class=FlowDemoSeeder (dilewati bila sudah ada SO).
 */
class FlowDemoSeeder extends Seeder
{
    private Carbon $base;

    public function run(): void
    {
        if (SalesOrder::exists() || ! Product::where('code', 'TS-BSC')->exists()) {
            return;
        }

        $this->base = today()->subDays(34);

        try {
            DB::transaction(fn () => $this->scenario());
        } finally {
            Carbon::setTestNow();
            Auth::logout();
        }
    }

    private function scenario(): void
    {
        $users = User::pluck('id', 'email');
        $as = function (string $role) use ($users) {
            Auth::loginUsingId($users["{$role}@erp.test"] ?? $users['admin@erp.test']);
        };

        $purchase = app(PurchaseService::class);
        $sales = app(SalesService::class);
        $production = app(ProductionService::class);
        $subcontract = app(SubcontractService::class);
        $piecework = app(PieceworkService::class);
        $payroll = app(PayrollService::class);
        $invoices = app(InvoiceService::class);
        $bills = app(BillService::class);
        $payments = app(PaymentService::class);
        $returns = app(ReturnService::class);
        $cashBank = app(CashBankService::class);
        $assets = app(FixedAssetService::class);
        $defects = app(DefectService::class);
        $assetCategory = fn (string $code) => AssetCategory::where('code', $code)->value('id');

        $material = fn (string $code) => Material::where('code', $code)->firstOrFail();
        $supplier = fn (string $code) => Supplier::where('code', $code)->firstOrFail();
        $customer = fn (string $code) => Customer::where('code', $code)->firstOrFail();
        $kaos = Product::with('variants', 'operations')->where('code', 'TS-BSC')->firstOrFail();
        $kemeja = Product::with('variants', 'operations')->where('code', 'KMJ-OXF')->firstOrFail();
        $variant = fn (Product $p, string $size) => $p->variants->firstWhere('size', $size);
        $op = fn (Product $p, string $name) => (string) $p->operations->firstWhere('name', $name)->id;
        $emp = fn (string $code) => Employee::where('code', $code)->value('id');
        $bank = Account::where('code', '1102')->value('id');
        $cash = Account::where('code', '1101')->value('id');

        // Hari 0: setoran modal awal ke kas & bank
        $this->on(0);
        $as('keuangan');
        $cashBank->record(CashTransactionType::In, ['date' => $this->d(0), 'cash_account_id' => $bank, 'party_name' => 'Pemilik', 'description' => 'Setoran modal awal ke rekening bank'],
            [['account_id' => Account::where('code', '3101')->value('id'), 'amount' => 150000000]]);
        $cashBank->record(CashTransactionType::Transfer, ['date' => $this->d(0), 'cash_account_id' => $bank, 'to_account_id' => $cash, 'amount' => 5000000, 'description' => 'Isi kas kecil pabrik']);

        // Hari 0: aset lama (saldo awal) mulai disusutkan bulan ini, lalu beli mesin baru tunai & kredit
        $assets->create([
            'acquisition_type' => 'opening', 'name' => 'Mesin jahit high speed (10 unit)', 'asset_category_id' => $assetCategory('MSN'),
            'location' => 'Lantai 2 – Jahit', 'acquisition_date' => $this->base->copy()->subMonths(24)->startOfMonth()->toDateString(),
            'cost' => 75000000, 'opening_accumulated' => 18750000, 'depreciation_start' => $this->base->copy()->startOfMonth()->toDateString(),
        ]);
        $assets->create([
            'acquisition_type' => 'opening', 'name' => 'Tanah & lahan pabrik', 'asset_category_id' => $assetCategory('TNH'),
            'acquisition_date' => $this->base->copy()->subYears(6)->toDateString(), 'cost' => 350000000,
            'depreciation_start' => $this->base->copy()->startOfMonth()->toDateString(),
        ]);
        $this->on(2);
        $assets->create([
            'acquisition_type' => 'cash', 'name' => 'Mesin obras 4 benang', 'asset_category_id' => $assetCategory('MSN'), 'serial_number' => 'OB-7781',
            'location' => 'Lantai 2 – Jahit', 'acquisition_date' => $this->d(2), 'cost' => 18000000, 'cash_account_id' => $bank, 'reference' => 'KW-0921',
        ]);
        $machineSupplier = Supplier::firstOrCreate(['code' => 'SUP-003'], ['name' => 'CV Sinar Mesin Garmen', 'contact_person' => 'Wawan', 'phone' => '0812-9999-0001', 'address' => 'Bandung', 'payment_terms' => 30]);
        $assets->create([
            'acquisition_type' => 'credit', 'name' => 'Mesin potong kain otomatis', 'asset_category_id' => $assetCategory('MSN'), 'serial_number' => 'CUT-2201',
            'location' => 'Lantai 1 – Potong', 'acquisition_date' => $this->d(2), 'cost' => 24000000, 'tax_rate' => 11, 'supplier_id' => $machineSupplier->id, 'reference' => 'INV-SMG-118',
        ]);

        // Hari 0: dua pesanan pelanggan
        $as('sales');
        $soA = $this->salesOrder($customer('CUS-002'), 0, 14, [
            [$variant($kaos, 'S'), 50, 65000], [$variant($kaos, 'M'), 80, 65000], [$variant($kaos, 'L'), 100, 65000], [$variant($kaos, 'XL'), 70, 65000],
        ], 'Seragam kaos karyawan');
        $soB = $this->salesOrder($customer('CUS-001'), 0, 20, [
            [$variant($kemeja, 'M'), 40, 175000], [$variant($kemeja, 'L'), 40, 175000], [$variant($kemeja, 'XL'), 40, 175000],
        ]);

        // Hari 1: PO kain (perlu persetujuan) & PO aksesoris
        $this->on(1);
        $as('purchasing');
        $po1 = $this->purchaseOrder($supplier('SUP-001'), 1, 3, [[$material('KN-CMB30-HTM'), 150, 125000], [$material('RIB-HTM'), 20, 95000]], 'Isi ulang stok kain kaos');
        $purchase->submit($po1);
        $po2 = $this->purchaseOrder($supplier('SUP-002'), 1, 5, [[$material('LBL-WOVEN'), 2000, 600], [$material('PLS-OPP'), 2000, 300]]);
        $purchase->submit($po2);
        $po3 = $this->purchaseOrder($supplier('SUP-001'), 1, 3, [[$material('BNG-POL-PTH'), 20, 18000]], 'Dibuat dari MRP: benang putih di bawah stok minimum');
        $purchase->submit($po3);
        $as('keuangan');
        $purchase->approve($po1->fresh(), User::find($users['keuangan@erp.test']), 'Harga sesuai kontrak');

        // Hari 2: SPK kaos (internal penuh) & SPK kemeja (jahit + finishing dimaklonkan)
        $this->on(2);
        $as('produksi');
        $spkA = $this->spk($kaos, $soA, 2, 12);
        $production->start($spkA);
        $spkB = $this->spk($kemeja, $soB, 2, 16);
        $suggest = SubcontractService::suggestedMaterials($spkB->fresh('materials'), 120);
        foreach (['LBL-WOVEN', 'PLS-OPP'] as $internal) {
            $suggest[$material($internal)->id] = 0; // label & plastik dipasang sendiri saat packing
        }
        $mkl = $subcontract->create($spkB, [
            'supplier_id' => $supplier('MKL-001')->id, 'order_date' => $this->d(2), 'due_date' => $this->d(12),
            'stages' => ['sewing', 'finishing'], 'quantity' => 120, 'rate' => 15000, 'notes' => 'Jahit & finishing kemeja oxford',
        ], $suggest);
        $production->start($spkB->fresh());

        // Hari 3: barang datang, potong kemeja, bahan dikirim ke vendor maklon
        $this->on(3);
        $as('gudang');
        $purchase->receive($po1->fresh('items'), $po1->items->pluck('quantity', 'id')->all(), ['receipt_date' => $this->d(3), 'delivery_note' => 'SJ-TN-2210']);
        $purchase->receive($po3->fresh('items'), $po3->items->pluck('quantity', 'id')->all(), ['receipt_date' => $this->d(3), 'delivery_note' => 'SJ-TN-2211']);
        $label = $po2->items->firstWhere('material_id', $material('LBL-WOVEN')->id);
        $purchase->receive($po2->fresh('items'), [$label->id => 1000], ['receipt_date' => $this->d(3), 'delivery_note' => 'SJ-AGJ-0981', 'notes' => 'Plastik menyusul']);
        $as('produksi');
        $this->stage($production, $spkB, 'cutting', 120);
        $piecework->record($spkB->fresh(), ['date' => $this->d(3)], [['employee_id' => $emp('KRY-001'), 'operation' => $op($kemeja, 'Potong'), 'quantity' => 120]]);
        $as('gudang');
        $subcontract->dispatch($mkl->fresh('materials'), $mkl->materials->mapWithKeys(fn ($m) => [$m->id => $m->planned_quantity])->all(),
            ['date' => $this->d(3), 'vehicle_number' => 'D 8812 KA', 'driver_name' => 'Ujang']);

        // Hari 4: hasil kerja borongan kaos, tagihan kain
        $this->on(4);
        $as('produksi');
        $piecework->record($spkA->fresh(), ['date' => $this->d(4)], [
            ['employee_id' => $emp('KRY-001'), 'operation' => $op($kaos, 'Potong'), 'quantity' => 240],
            ['employee_id' => $emp('KRY-002'), 'operation' => $op($kaos, 'Obras bahu'), 'quantity' => 120],
            ['employee_id' => $emp('KRY-002'), 'operation' => $op($kaos, 'Pasang lengan'), 'quantity' => 120],
            ['employee_id' => $emp('KRY-003'), 'operation' => $op($kaos, 'Obras samping'), 'quantity' => 120],
            ['employee_id' => $emp('KRY-005'), 'operation' => $op($kaos, 'Pasang rib leher'), 'quantity' => 120],
            ['employee_id' => $emp('KRY-004'), 'operation' => 'full', 'quantity' => 20],
        ]);
        $this->stage($production, $spkA, 'cutting', 300);
        $as('keuangan');
        $bills->createFromPurchaseOrder($po1->fresh('items'), $this->billLines($po1), ['bill_date' => $this->d(4), 'supplier_invoice_number' => 'INV/TN/10/2210', 'tax_rate' => 11, 'tax_invoice_number' => '010.002-26.00004521', 'tax_invoice_date' => $this->d(4)]);
        $bills->createFromPurchaseOrder($po3->fresh('items'), $this->billLines($po3), ['bill_date' => $this->d(4), 'supplier_invoice_number' => 'INV/TN/10/2211']);

        // Hari 5: kasbon karyawan
        $this->on(5);
        $payroll->recordAdvance(['employee_id' => $emp('KRY-001'), 'date' => $this->d(5), 'amount' => 150000, 'payment_method' => 'cash', 'notes' => 'Kasbon keperluan keluarga']);

        // Hari 6: sisa aksesoris datang, sebagian label cacat diretur, tagihan aksesoris
        $this->on(6);
        $as('gudang');
        $purchase->receive($po2->fresh('items'), $po2->fresh('items')->items->mapWithKeys(fn ($i) => [$i->id => $i->outstandingQuantity()])->all(), ['receipt_date' => $this->d(6), 'delivery_note' => 'SJ-AGJ-0995']);
        $as('keuangan');
        $bills->createFromPurchaseOrder($po2->fresh('items'), $this->billLines($po2), ['bill_date' => $this->d(6), 'supplier_invoice_number' => 'AGJ-1045']);
        $as('gudang');
        $returns->createPurchaseReturn($po2->fresh('items'), [$label->id => 150], ['return_date' => $this->d(6), 'reason' => 'Label woven salah warna']);

        // Hari 7: penggajian minggu pertama (hari 0–6)
        $this->on(7);
        $as('produksi');
        $piecework->record($spkA->fresh(), ['date' => $this->d(7)], [
            ['employee_id' => $emp('KRY-002'), 'operation' => $op($kaos, 'Obras bahu'), 'quantity' => 120],
            ['employee_id' => $emp('KRY-002'), 'operation' => $op($kaos, 'Pasang lengan'), 'quantity' => 118, 'reject_quantity' => 2],
            ['employee_id' => $emp('KRY-003'), 'operation' => $op($kaos, 'Obras samping'), 'quantity' => 120],
            ['employee_id' => $emp('KRY-003'), 'operation' => $op($kaos, 'Kelim bawah & lengan'), 'quantity' => 120],
            ['employee_id' => $emp('KRY-005'), 'operation' => $op($kaos, 'Pasang rib leher'), 'quantity' => 120],
            ['employee_id' => $emp('KRY-005'), 'operation' => $op($kaos, 'Buang benang & lipat'), 'quantity' => 120],
            ['employee_id' => $emp('KRY-004'), 'operation' => 'full', 'quantity' => 20],
        ]);
        $week1 = $payroll->create(['period_start' => $this->d(0), 'period_end' => $this->d(6)]);
        $line = $week1->lines()->where('employee_id', $emp('KRY-001'))->first();
        $payroll->updateLines($week1, [$line->id => ['advance_deduction' => 75000, 'notes' => 'Cicilan kasbon 1']]);
        $as('keuangan');
        $payroll->approve($week1->fresh());
        $payroll->pay($week1->fresh(), ['payment_date' => $this->d(7), 'payment_method' => 'cash']);

        // Hari 10: borongan kaos selesai, tahapan jahit–packing
        $this->on(10);
        $as('produksi');
        $piecework->record($spkA->fresh(), ['date' => $this->d(10)], [
            ['employee_id' => $emp('KRY-003'), 'operation' => $op($kaos, 'Kelim bawah & lengan'), 'quantity' => 120],
            ['employee_id' => $emp('KRY-005'), 'operation' => $op($kaos, 'Buang benang & lipat'), 'quantity' => 120],
            ['employee_id' => $emp('KRY-001'), 'operation' => $op($kaos, 'Packing'), 'quantity' => 240],
            ['employee_id' => $emp('KRY-004'), 'operation' => 'full', 'quantity' => 20],
        ]);
        $this->stage($production, $spkA, 'sewing', 298, 2);
        $this->stage($production, $spkA, 'finishing', 298);

        // Hari 11: QC & packing kaos, SPK kaos selesai
        $this->on(11);
        $this->stage($production, $spkA, 'qc', 296, 2);
        $this->stage($production, $spkA, 'packing', 296);
        $production->complete($spkA->fresh(), $this->finished($spkA, 296), 4);

        // Hari 12: hasil maklon diterima, kaos dikirim & difakturkan
        $this->on(12);
        $as('gudang');
        $subcontract->receive($mkl->fresh(), ['date' => $this->d(12), 'good_quantity' => 118, 'reject_quantity' => 2, 'vendor_reference' => 'SJ-ANI-031']);
        $this->deliverAvailable($sales, $soA, 12, 'D 1845 BB', 'Asep');
        $as('keuangan');
        $invA = $invoices->createFromSalesOrder($soA->fresh('items'), $this->uninvoiced($soA), ['invoice_date' => $this->d(12), 'due_date' => $this->d(42), 'tax_rate' => 11]);
        app(VatService::class)->setTaxInvoice($invA, '010.000-26.00000101', $this->d(12)); // faktur kemeja (invB) sengaja belum diisi

        // Hari 13: sisa bahan kembali dari vendor, order maklon ditutup, sisa SO kaos ditutup
        $this->on(13);
        $as('gudang');
        $fabric = $mkl->materials()->where('material_id', $material('KN-OXF-BRU')->id)->first();
        $subcontract->returnMaterials($mkl->fresh(), [$fabric->id => 3], ['date' => $this->d(13), 'notes' => 'Sisa potongan kain']);
        $as('produksi');
        $subcontract->close($mkl->fresh(), 'Hasil lengkap, sisa bahan sudah kembali');
        $as('sales');
        $soA->refresh()->update(['status' => SalesOrderStatus::Closed]);
        ActivityLog::record('closed', 'Menutup SO, sisa qty tidak dikirim: 4 pcs reject QC, pelanggan setuju', $soA);

        // Hari 14: penggajian minggu kedua, kemeja selesai, tagihan maklon
        $this->on(14);
        $as('produksi');
        $week2 = $payroll->create(['period_start' => $this->d(7), 'period_end' => $this->d(13)]);
        $line = $week2->lines()->where('employee_id', $emp('KRY-001'))->first();
        $payroll->updateLines($week2, [$line->id => ['advance_deduction' => 40000, 'notes' => 'Cicilan kasbon 2']]);
        $this->stage($production, $spkB, 'qc', 118);
        $this->stage($production, $spkB, 'packing', 118);
        $production->complete($spkB->fresh(), $this->finished($spkB, 118), 2);
        $as('keuangan');
        $payroll->approve($week2->fresh());
        $payroll->pay($week2->fresh(), ['payment_date' => $this->d(14), 'payment_method' => 'cash']);
        $bills->createFromSubcontract($mkl->fresh(), $mkl->receipts()->pluck('id')->all(), ['bill_date' => $this->d(14), 'supplier_invoice_number' => 'NOTA-ANI-031']);

        // Hari 15–16: kemeja dikirim & difakturkan, biaya listrik
        $this->on(15);
        $as('gudang');
        $this->deliverAvailable($sales, $soB, 15, 'D 1845 BB', 'Asep');
        $as('keuangan');
        $invB = $invoices->createFromSalesOrder($soB->fresh('items'), $this->uninvoiced($soB), ['invoice_date' => $this->d(15), 'due_date' => $this->d(45), 'tax_rate' => 11]);
        $this->on(16);
        $cashBank->record(CashTransactionType::Out, ['date' => $this->d(16), 'cash_account_id' => $bank, 'party_name' => 'PLN', 'reference' => 'TAG-PLN-10', 'description' => 'Tagihan listrik pabrik'],
            [['account_id' => Account::where('code', '5201')->value('id'), 'amount' => 1250000]]);

        // Hari 18: retur kaos dari pelanggan (nota kredit langsung mengurangi invoice)
        $this->on(18);
        $as('sales');
        $itemM = $soA->items()->where('product_variant_id', $variant($kaos, 'M')->id)->first();
        $returns->createSalesReturn($soA->fresh('items'), [$itemM->id => 5], ['return_date' => $this->d(18), 'reason' => 'Jahitan samping lepas', 'defects' => [$itemM->id => 3]]);

        // Hari 19: 2 pcs cacat dijahit ulang (masuk stok jual lagi), 1 pcs masih di stok cacat
        $this->on(19);
        $as('produksi');
        $defects->record(DefectHandlingType::Rework, ['date' => $this->d(19), 'notes' => 'Jahit ulang sisi samping', 'extra_cost' => 6000, 'cash_account_id' => $cash],
            [['product_variant_id' => $variant($kaos, 'M')->id, 'quantity' => 2]]);

        // Hari 20: pelunasan & pembayaran
        $this->on(20);
        $as('keuangan');
        $payments->record(PaymentType::Receipt, $soA->customer_id, [$invA->id => $invA->fresh()->outstanding()],
            ['payment_date' => $this->d(20), 'method' => 'transfer', 'cash_account_id' => $bank, 'reference' => 'TRF-BCA-88123']);
        $billPo1 = $po1->bills()->first();
        $billMkl = $mkl->bills()->first();
        $payments->record(PaymentType::Disbursement, $po1->supplier_id, [$billPo1->id => $billPo1->fresh()->outstanding()],
            ['payment_date' => $this->d(20), 'method' => 'transfer', 'cash_account_id' => $bank, 'reference' => 'TRF-OUT-2210']);
        $payments->record(PaymentType::Disbursement, $mkl->supplier_id, [$billMkl->id => $billMkl->fresh()->outstanding()],
            ['payment_date' => $this->d(20), 'method' => 'cash', 'cash_account_id' => $cash]);

        // Hari 22–25: sewa kantor, cicilan pelanggan kemeja
        $this->on(22);
        $cashBank->record(CashTransactionType::Out, ['date' => $this->d(22), 'cash_account_id' => $bank, 'party_name' => 'Bpk. Hendra', 'description' => 'Sewa kantor bulan berjalan'],
            [['account_id' => Account::where('code', '6102')->value('id'), 'amount' => 3000000]]);
        $this->on(25);
        $payments->record(PaymentType::Receipt, $soB->customer_id, [$invB->id => 10000000],
            ['payment_date' => $this->d(25), 'method' => 'transfer', 'cash_account_id' => $bank, 'reference' => 'TRF-MANDIRI-551']);

        // Hari 26–30: penawaran harga → repeat order kaos (jadi SO), penawaran lain ditolak / menunggu jawaban
        $this->on(26);
        $as('sales');
        $quotes = app(QuotationService::class);
        $kaosLines = fn (int $price) => [
            ['product_variant_id' => $variant($kaos, 'M')->id, 'quantity' => 60, 'price' => $price],
            ['product_variant_id' => $variant($kaos, 'L')->id, 'quantity' => 60, 'price' => $price, 'description' => 'Sablon logo dada kiri'],
        ];
        $quoteC = $quotes->save(['customer_id' => $customer('CUS-001')->id, 'quotation_date' => $this->d(26), 'subject' => 'Repeat order kaos hitam', 'lead_time_days' => 14, 'tax_rate' => 11,
            'terms' => 'DP 30% saat order, pelunasan 30 hari setelah barang diterima.'], $kaosLines(65000));
        $quotes->send($quoteC);
        $quoteLost = $quotes->save(['customer_id' => $customer('CUS-002')->id, 'quotation_date' => $this->d(26), 'subject' => 'Kemeja seragam 200 pcs', 'tax_rate' => 11],
            [['product_variant_id' => $variant($kemeja, 'M')->id, 'quantity' => 200, 'price' => 180000]]);
        $quotes->send($quoteLost);
        $this->on(28);
        $quotes->reject($quoteLost, 'Pelanggan memilih vendor lain (harga)');
        $quoteC = $quotes->revise($quoteC); // nego harga turun
        $quoteC = $quotes->save(['customer_id' => $quoteC->customer_id, 'quotation_date' => $this->d(28), 'valid_until' => $this->d(42), 'subject' => $quoteC->subject,
            'lead_time_days' => 14, 'tax_rate' => 11, 'terms' => $quoteC->terms], $kaosLines(62000), $quoteC);
        $quotes->send($quoteC);

        // Hari 30–33: pesanan baru sedang berjalan (dibiarkan terbuka)
        $this->on(30);
        $as('sales');
        $soC = $quotes->convert($quoteC, ['order_date' => $this->d(30), 'delivery_date' => $this->d(44)]);
        $soC->update(['status' => SalesOrderStatus::Confirmed]);
        ActivityLog::record('confirmed', 'Mengonfirmasi SO', $soC);
        $this->on(31);
        $as('produksi');
        $spkC = $this->spk($kaos, $soC, 31, 40);
        $production->start($spkC);
        $this->on(33);
        $piecework->record($spkC->fresh(), ['date' => $this->d(33)], [
            ['employee_id' => $emp('KRY-001'), 'operation' => $op($kaos, 'Potong'), 'quantity' => 120],
            ['employee_id' => $emp('KRY-002'), 'operation' => $op($kaos, 'Obras bahu'), 'quantity' => 60],
        ]);
        $this->stage($production, $spkC, 'cutting', 120);
        $payroll->create(['period_start' => $this->d(28), 'period_end' => $this->d(34)]);

        // Hari ini: SO baru (draft) & PO kain putih menunggu persetujuan
        $this->on(34);
        $as('sales');
        $this->salesOrder($customer('CUS-002'), 34, 50, [[$variant($kemeja, 'L'), 30, 175000]], 'Kemeja seragam kantor', confirm: false);
        $quotes->send($quotes->save(['customer_id' => $customer('CUS-002')->id, 'quotation_date' => $this->d(34), 'subject' => 'Kemeja seragam bordir logo', 'lead_time_days' => 21, 'tax_rate' => 11],
            [['product_variant_id' => $variant($kemeja, 'XL')->id, 'quantity' => 50, 'price' => 185000]]));
        $as('purchasing');
        $po4 = $this->purchaseOrder($supplier('SUP-001'), 34, 38, [[$material('KN-CMB30-PTH'), 100, 118000]], 'Stok kain putih untuk pesanan kaos putih');
        $purchase->submit($po4);

        // Penyusutan aset tetap s.d. bulan lalu
        $as('keuangan');
        app(DepreciationService::class)->run(today()->startOfMonth()->subMonthNoOverflow());

        // Tutup masa PPN bulan lalu (bila bisa), kurang bayar disetor lewat kas keluar
        $vat = app(VatService::class);
        $lastMonth = today()->startOfMonth()->subMonthNoOverflow();
        if ($vat->closeBlocker($lastMonth) === null) {
            $vat->close($lastMonth, 'Data demo');
        }
    }

    /** Pindahkan "hari ini" ke hari ke-n skenario (jam kerja) agar tanggal mutasi & jurnal sesuai dokumen. */
    private function on(int $day): void
    {
        Carbon::setTestNow($this->base->copy()->addDays($day)->setTime(9, 0));
    }

    private function d(int $day): string
    {
        return $this->base->copy()->addDays($day)->toDateString();
    }

    private function salesOrder(Customer $customer, int $day, int $deliverDay, array $lines, ?string $notes = null, bool $confirm = true): SalesOrder
    {
        $items = collect($lines)->map(fn ($l) => ['product_variant_id' => $l[0]->id, 'quantity' => $l[1], 'price' => $l[2], 'subtotal' => $l[1] * $l[2]]);
        $order = SalesOrder::create([
            'number' => DocumentNumber::generate(SalesOrder::class, 'SO', Carbon::parse($this->d($day))),
            'customer_id' => $customer->id,
            'order_date' => $this->d($day),
            'delivery_date' => $this->d($deliverDay),
            'notes' => $notes,
            'subtotal' => $items->sum('subtotal'),
            'discount' => 0,
            'total' => $items->sum('subtotal'),
            'status' => $confirm ? SalesOrderStatus::Confirmed : SalesOrderStatus::Draft,
            'created_by' => Auth::id(),
        ]);
        $order->items()->createMany($items->all());
        ActivityLog::record('created', "Membuat SO {$order->number}", $order);
        if ($confirm) {
            ActivityLog::record('confirmed', 'Mengonfirmasi SO', $order);
        }

        return $order;
    }

    private function purchaseOrder(Supplier $supplier, int $day, int $expectedDay, array $lines, ?string $notes = null): PurchaseOrder
    {
        $items = collect($lines)->map(fn ($l) => ['material_id' => $l[0]->id, 'quantity' => $l[1], 'price' => $l[2], 'subtotal' => $l[1] * $l[2]]);
        $order = PurchaseOrder::create([
            'number' => DocumentNumber::generate(PurchaseOrder::class, 'PO', Carbon::parse($this->d($day))),
            'supplier_id' => $supplier->id,
            'order_date' => $this->d($day),
            'expected_date' => $this->d($expectedDay),
            'notes' => $notes,
            'status' => PurchaseOrderStatus::Draft,
            'total' => $items->sum('subtotal'),
            'created_by' => Auth::id(),
        ]);
        $order->items()->createMany($items->all());
        ActivityLog::record('created', "Membuat PO {$order->number}", $order);

        return $order->load('items');
    }

    private function spk(Product $product, SalesOrder $so, int $day, int $dueDay): ProductionOrder
    {
        $order = ProductionOrder::create([
            'number' => DocumentNumber::generate(ProductionOrder::class, 'SPK', Carbon::parse($this->d($day))),
            'product_id' => $product->id,
            'bom_id' => Bom::where('product_id', $product->id)->where('is_active', true)->value('id'),
            'sales_order_id' => $so->id,
            'start_date' => $this->d($day),
            'due_date' => $this->d($dueDay),
            'status' => ProductionOrderStatus::Draft,
            'created_by' => Auth::id(),
        ]);
        app(ProductionService::class)->syncItems($order, $so->items->pluck('quantity', 'product_variant_id')->all());
        ActivityLog::record('created', "Membuat SPK {$order->number}", $order);

        return $order->fresh();
    }

    private function stage(ProductionService $service, ProductionOrder $spk, string $stage, float $output, float $reject = 0): void
    {
        $row = $spk->stages()->where('stage', $stage)->firstOrFail();
        $service->updateStage($row, ['status' => StageStatus::Done->value, 'output_quantity' => $output, 'reject_quantity' => $reject, 'pic' => 'Kepala Line 1']);
    }

    /** Jumlah jadi per ukuran = rencana, kekurangan dikurangi dari ukuran terbanyak. */
    private function finished(ProductionOrder $spk, float $total): array
    {
        $items = $spk->items()->get();
        $finished = $items->pluck('quantity', 'id')->map(fn ($q) => (float) $q)->all();
        $short = array_sum($finished) - $total;
        foreach ($items->sortByDesc('quantity') as $item) {
            $cut = min($short, $finished[$item->id]);
            $finished[$item->id] -= $cut;
            $short -= $cut;
        }

        return $finished;
    }

    private function deliverAvailable(SalesService $service, SalesOrder $so, int $day, string $vehicle, string $driver): void
    {
        $quantities = $so->fresh('items.variant')->items
            ->mapWithKeys(fn ($i) => [$i->id => min($i->outstandingQuantity(), max(0, (float) $i->variant->stock))])
            ->all();
        $service->deliver($so->fresh(), $quantities, ['delivery_date' => $this->d($day), 'vehicle_number' => $vehicle, 'driver_name' => $driver, 'shipping_address' => $so->customer->address]);
    }

    private function uninvoiced(SalesOrder $so): array
    {
        return $so->fresh('items')->items->mapWithKeys(fn ($i) => [$i->id => $i->uninvoicedQuantity()])->all();
    }

    private function billLines(PurchaseOrder $po): array
    {
        return $po->fresh('items')->items->mapWithKeys(fn ($i) => [$i->id => ['quantity' => $i->unbilledQuantity(), 'price' => $i->price]])->all();
    }
}
