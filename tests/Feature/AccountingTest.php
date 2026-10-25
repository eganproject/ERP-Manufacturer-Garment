<?php

namespace Tests\Feature;

use App\Enums\JournalType;
use App\Enums\Role;
use App\Enums\StageStatus;
use App\Enums\StockMovementType;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Material;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\SubcontractOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\FinancialReports;
use App\Services\Accounting\LedgerRebuilder;
use App\Services\StockService;
use App\Support\LedgerAccounts;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountingTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-25 10:00:00');
        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create());

        $this->variant = Product::where('code', 'TS-BSC')->with('variants')->first()->variants->first();
        DB::transaction(function () {
            $this->variant->forceFill(['stock' => 0, 'average_cost' => 0])->save();
            app(StockService::class)->move($this->variant, 100, StockMovementType::Adjustment, notes: 'Saldo awal', unitCost: 40000);
        });

        // Saldo awal stok contoh dijurnal ke ekuitas saldo awal
        app(LedgerRebuilder::class)->rebuild();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Saldo debit − kredit akun berdasarkan kode. */
    private function balance(string $code): float
    {
        $id = Account::where('code', $code)->value('id');

        return round((float) JournalLine::where('account_id', $id)->sum('debit') - (float) JournalLine::where('account_id', $id)->sum('credit'), 2);
    }

    private function assertBooksBalanced(): void
    {
        $this->assertEqualsWithDelta((float) JournalLine::sum('debit'), (float) JournalLine::sum('credit'), 0.001);

        foreach (JournalEntry::with('lines')->get() as $entry) {
            $this->assertEqualsWithDelta((float) $entry->lines->sum('debit'), (float) $entry->lines->sum('credit'), 0.001, "Jurnal {$entry->number} tidak seimbang");
        }

        $sheet = app(FinancialReports::class)->balanceSheet(today());
        $this->assertTrue($sheet['balanced'], 'Neraca tidak seimbang, selisih '.$sheet['difference']);
    }

    /** PO 100 kg @120.000, terima 60, tagihan 60 @125.000 + PPN 11%, dibayar lunas via transfer. */
    private function purchaseCycle(): PurchaseOrder
    {
        $material = Material::where('code', 'KN-CMB30-HTM')->first();
        $this->post(route('purchase-orders.store'), [
            'supplier_id' => Supplier::first()->id,
            'order_date' => '2026-10-01',
            'items' => [['material_id' => $material->id, 'quantity' => 100, 'price' => 120000]],
        ]);
        $po = PurchaseOrder::latest('id')->first();
        $this->post(route('purchase-orders.submit', $po));
        $this->post(route('purchase-orders.receive.store', $po), ['receipt_date' => '2026-10-03', 'items' => [$po->items()->first()->id => 60]])
            ->assertSessionHasNoErrors();
        $this->post(route('bills.store'), [
            'purchase_order_id' => $po->id, 'bill_date' => '2026-10-04', 'tax_rate' => 11,
            'items' => [$po->items()->first()->id => ['quantity' => 60, 'price' => 125000]],
        ])->assertSessionHasNoErrors();
        $bill = $po->bills()->first();
        $this->post(route('payments.store'), [
            'type' => 'disbursement', 'party_id' => $po->supplier_id, 'payment_date' => '2026-10-05', 'method' => 'transfer',
            'allocations' => [$bill->id => (float) $bill->total],
        ])->assertSessionHasNoErrors();

        return $po;
    }

    /** SO 50 pcs @65.000 diskon 50.000; kirim & tagih 30 pcs (HPP 40.000) PPN 11%, dibayar tunai. */
    private function salesCycle(): SalesOrder
    {
        $this->post(route('sales-orders.store'), [
            'customer_id' => Customer::first()->id,
            'order_date' => '2026-10-01',
            'discount' => 50000,
            'items' => [['product_variant_id' => $this->variant->id, 'quantity' => 50, 'price' => 65000]],
        ]);
        $so = SalesOrder::latest('id')->first();
        $this->post(route('sales-orders.confirm', $so));
        $item = $so->items()->first();
        $this->post(route('sales-orders.deliver.store', $so), ['delivery_date' => '2026-10-02', 'items' => [$item->id => 30]])->assertSessionHasNoErrors();
        $this->post(route('invoices.store'), ['sales_order_id' => $so->id, 'invoice_date' => '2026-10-02', 'tax_rate' => 11, 'items' => [$item->id => 30]])
            ->assertSessionHasNoErrors();

        return $so->fresh();
    }

    public function test_chart_of_accounts_is_installed_with_system_accounts(): void
    {
        $this->assertTrue(Account::where('code', '1201')->where('is_system', true)->exists());
        $this->assertSame('credit', Account::where('code', '1509')->value('normal_balance'));
        $this->assertSame('debit', Account::where('code', '4102')->value('normal_balance'));
        $this->assertTrue(Account::where('code', '1000')->value('is_header'));

        // Saldo awal stok: persediaan vs ekuitas saldo awal
        $this->assertEquals(4000000, $this->balance('1303'));
        $this->assertLessThan(0, $this->balance('3901'));
        $this->assertBooksBalanced();
    }

    public function test_purchase_and_sales_cycle_post_expected_journals(): void
    {
        $rawBefore = $this->balance('1301');
        $this->purchaseCycle();

        // Penerimaan 60 × 120.000 ke persediaan; tagihan 125.000 → selisih harga 300.000
        $this->assertEquals($rawBefore + 7200000, $this->balance('1301'));
        $this->assertEquals(0, $this->balance('2102'));
        $this->assertEquals(300000, $this->balance('5102'));
        $this->assertEquals(825000, $this->balance('1401'));
        $this->assertEquals(0, $this->balance('2101'));
        $this->assertEquals(-8325000, $this->balance('1102'));

        $so = $this->salesCycle();
        $invoice = Invoice::first();

        $this->assertEquals(1200000, $this->balance('5101'));          // HPP 30 × 40.000
        $this->assertEquals(4000000 - 1200000, $this->balance('1303'));
        $this->assertEquals(2131200, $this->balance('1201'));
        $this->assertEquals(-1950000, $this->balance('4101'));
        $this->assertEquals(30000, $this->balance('4103'));
        $this->assertEquals(-211200, $this->balance('2105'));

        $this->post(route('payments.store'), [
            'type' => 'receipt', 'party_id' => $so->customer_id, 'payment_date' => '2026-10-10', 'method' => 'cash',
            'allocations' => [$invoice->id => 2131200],
        ])->assertSessionHasNoErrors();
        $this->assertEquals(0, $this->balance('1201'));
        $this->assertEquals(2131200, $this->balance('1101'));

        // Laba rugi Oktober: penjualan bersih 1.920.000 − HPP (1.200.000 + selisih 300.000)
        $pl = app(FinancialReports::class)->profitLoss(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
        $this->assertEquals(1920000, $pl['net_revenue']);
        $this->assertEquals(420000, $pl['gross_profit']);
        $this->assertEquals(420000, $pl['net_profit']);

        $tb = app(FinancialReports::class)->trialBalance(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
        $this->assertEqualsWithDelta($tb['totals']['closing_debit'], $tb['totals']['closing_credit'], 0.001);
        $this->assertBooksBalanced();

        // Pembayaran dibatalkan → jurnal dibalik, piutang muncul lagi
        $payment = Payment::where('type', 'receipt')->first();
        $this->post(route('payments.cancel', $payment), ['reason' => 'Salah input'])->assertSessionHasNoErrors();
        $this->assertEquals(2131200, $this->balance('1201'));
        $this->assertEquals(0, $this->balance('1101'));
        $this->assertTrue($payment->fresh()->status->value === 'cancelled');
        $this->assertSame(1, JournalEntry::where('source_type', 'payment')->where('source_id', $payment->id)->whereNotNull('reversed_at')->count());

        // Invoice dibatalkan → penjualan & PPN dibalik
        $this->post(route('invoices.cancel', $invoice))->assertSessionHasNoErrors();
        $this->assertEquals(0, $this->balance('1201'));
        $this->assertEquals(0, $this->balance('4101'));
        $this->assertEquals(0, $this->balance('2105'));
        $this->assertBooksBalanced();
    }

    public function test_sales_return_credit_note_and_purchase_return_debit_note(): void
    {
        $so = $this->salesCycle();
        $invoice = Invoice::first();

        // Retur 10 pcs: nota kredit 10 × 65.000 × (1 − 50.000/3.250.000) = 640.000 + PPN 70.400, langsung mengurangi invoice
        $this->post(route('sales-returns.store'), [
            'order_id' => $so->id, 'return_date' => '2026-10-10', 'reason' => 'Cacat',
            'items' => [$so->items()->first()->id => 10],
        ])->assertSessionHasNoErrors();

        $this->assertEquals(640000, $this->balance('4102'));
        $this->assertEquals(-211200 + 70400, $this->balance('2105'));
        $this->assertEquals(0, $this->balance('2107'));                 // nota sudah terpakai
        $this->assertEquals(2131200 - 710400, $this->balance('1201'));
        $this->assertEquals(1200000 - 400000, $this->balance('5101'));  // HPP dikoreksi
        $this->assertEquals((float) $invoice->fresh()->outstanding(), $this->balance('1201'));

        // Retur pembelian dari barang yang sudah ditagih → nota debit dipakai mengurangi tagihan
        $material = Material::where('code', 'KN-CMB30-HTM')->first();
        $this->post(route('purchase-orders.store'), [
            'supplier_id' => Supplier::first()->id, 'order_date' => '2026-10-01',
            'items' => [['material_id' => $material->id, 'quantity' => 10, 'price' => 100000]],
        ]);
        $po = PurchaseOrder::latest('id')->first();
        $this->post(route('purchase-orders.submit', $po));
        $this->post(route('purchase-orders.receive.store', $po), ['receipt_date' => '2026-10-03', 'items' => [$po->items()->first()->id => 10]]);
        $this->post(route('bills.store'), [
            'purchase_order_id' => $po->id, 'bill_date' => '2026-10-04', 'tax_rate' => 11,
            'items' => [$po->items()->first()->id => ['quantity' => 6, 'price' => 100000]],
        ])->assertSessionHasNoErrors();
        $this->post(route('purchase-returns.store'), [
            'order_id' => $po->id, 'return_date' => '2026-10-06', 'reason' => 'Warna salah',
            'items' => [$po->items()->first()->id => 6],
        ])->assertSessionHasNoErrors();

        // 4 belum ditagih mengurangi hutang belum ditagih; 2 sudah ditagih jadi nota debit 222.000 → hutang usaha
        $this->assertEquals(0, $this->balance('2102'));
        $this->assertEquals(0, $this->balance('1202'));
        $bill = $po->bills()->first()->fresh();
        $this->assertEquals(-(float) $bill->outstanding(), $this->balance('2101'));
        $this->assertBooksBalanced();
    }

    public function test_production_and_subcontract_clear_wip_and_material_at_vendor(): void
    {
        $product = Product::where('code', 'TS-BSC')->with('variants')->first();
        $vendor = Supplier::where('code', 'MKL-001')->first();

        $this->post(route('production-orders.store'), [
            'product_id' => $product->id, 'start_date' => '2026-10-25',
            'items' => $product->variants->take(2)->mapWithKeys(fn ($v) => [$v->id => 10])->all(),
        ])->assertRedirect();
        $spk = ProductionOrder::latest('id')->with('materials')->first();

        // Jahit dimaklonkan; kain dikirim ke vendor
        $fabric = Material::where('code', 'KN-CMB30-HTM')->first();
        $this->post(route('subcontract-orders.store'), [
            'production_order_id' => $spk->id, 'supplier_id' => $vendor->id, 'order_date' => '2026-10-25',
            'stages' => ['sewing'], 'quantity' => 20, 'rate' => 5000,
            'materials' => [$fabric->id => (float) $spk->materials->firstWhere('material_id', $fabric->id)->required_quantity],
        ])->assertSessionHasNoErrors();
        $order = SubcontractOrder::latest('id')->first();

        $this->post(route('production-orders.start', $spk))->assertSessionHasNoErrors();
        $this->assertGreaterThan(0, $this->balance('1302'));

        $stages = $spk->stages()->get()->keyBy(fn ($s) => $s->stage->value);
        $this->put(route('production-orders.stages.update', [$spk, $stages['cutting']]), ['status' => StageStatus::Done->value, 'output_quantity' => 20])->assertSessionHasNoErrors();

        $lines = $order->materials->mapWithKeys(fn ($m) => [$m->id => (float) $m->planned_quantity])->filter()->all();
        $this->post(route('subcontract-orders.transfer.store', [$order, 'dispatch']), ['date' => '2026-10-25', 'items' => $lines])->assertSessionHasNoErrors();
        $this->assertGreaterThan(0, $this->balance('1304'));

        $this->post(route('subcontract-orders.receive.store', $order), ['date' => '2026-10-25', 'good_quantity' => 20])->assertSessionHasNoErrors();
        $this->assertEquals(-100000, $this->balance('2102'));  // jasa 20 × 5.000 belum ditagih

        $fabricLine = $order->materials()->where('material_id', $fabric->id)->first();
        $this->post(route('subcontract-orders.transfer.store', [$order, 'return']), ['date' => '2026-10-25', 'items' => [$fabricLine->id => 0.2]])->assertSessionHasNoErrors();
        $this->post(route('subcontract-orders.close', $order))->assertSessionHasNoErrors();
        $this->assertEquals(0, $this->balance('1304'));

        foreach (['finishing', 'qc', 'packing'] as $stage) {
            $this->put(route('production-orders.stages.update', [$spk, $stages[$stage]]), ['status' => StageStatus::Done->value, 'output_quantity' => 20])->assertSessionHasNoErrors();
        }

        $fgBefore = $this->balance('1303');
        $finished = $spk->items()->get()->mapWithKeys(fn ($i) => [$i->id => (float) $i->quantity])->all();
        $this->post(route('production-orders.complete', $spk), ['finished' => $finished])->assertSessionHasNoErrors();
        $spk->refresh();

        $this->assertEquals(0, $this->balance('1302'));
        $this->assertEqualsWithDelta($fgBefore + 20 * (float) $spk->unit_cost, $this->balance('1303'), 0.01);
        $this->assertEqualsWithDelta((float) $spk->total_cost, 20 * (float) $spk->unit_cost + $this->balance('5103'), 0.05);

        // Tagihan jasa maklon menutup hutang belum ditagih
        $this->post(route('bills.store'), ['subcontract_order_id' => $order->id, 'bill_date' => '2026-10-26', 'receipts' => $order->receipts()->pluck('id')->all()])
            ->assertSessionHasNoErrors();
        $this->assertEquals(0, $this->balance('2102'));
        $this->assertEquals(-100000, $this->balance('2101'));
        $this->assertBooksBalanced();
    }

    public function test_stock_adjustment_posts_inventory_difference(): void
    {
        $this->post(route('stock.adjustment.store'), [
            'item' => 'variant:'.$this->variant->id, 'date' => '2026-10-20', 'mode' => 'out', 'quantity' => 5, 'notes' => 'Hilang',
        ])->assertRedirect();

        $this->assertEquals(4000000 - 200000, $this->balance('1303'));
        $this->assertEquals(200000, $this->balance('5104'));
        $this->assertBooksBalanced();
    }

    public function test_manual_journal_must_balance_and_can_be_reversed(): void
    {
        $cash = Account::where('code', '1101')->value('id');
        $capital = Account::where('code', '3101')->value('id');
        $header = Account::where('code', '1100')->value('id');

        $this->get(route('journals.create'))->assertOk();

        $this->post(route('journals.store'), [
            'date' => '2026-10-01', 'description' => 'Setoran modal',
            'lines' => [['account_id' => $cash, 'debit' => 1000000], ['account_id' => $capital, 'credit' => 900000]],
        ])->assertSessionHasErrors('lines');

        $this->post(route('journals.store'), [
            'date' => '2026-10-01', 'description' => 'Setoran modal',
            'lines' => [['account_id' => $header, 'debit' => 1000000], ['account_id' => $capital, 'credit' => 1000000]],
        ])->assertSessionHasErrors('lines');

        $this->post(route('journals.store'), [
            'date' => '2026-10-01', 'description' => 'Setoran modal', 'reference' => 'BKM-01',
            'lines' => [['account_id' => $cash, 'debit' => 1000000], ['account_id' => $capital, 'credit' => 1000000]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $entry = JournalEntry::where('type', JournalType::Manual)->first();
        $this->assertSame('JU-202610-0001', $entry->number);
        $this->assertEquals(1000000, $this->balance('1101'));
        $this->get(route('journals.show', $entry))->assertOk()->assertSee('Setoran modal');

        $this->post(route('journals.reverse', $entry), ['date' => '2026-10-02', 'reason' => 'Salah akun'])->assertRedirect();
        $this->assertTrue($entry->fresh()->isReversed());
        $this->assertEquals(0, $this->balance('1101'));

        // Jurnal otomatis tidak bisa dibalik manual
        $auto = JournalEntry::where('type', JournalType::Auto)->first();
        $this->post(route('journals.reverse', $auto), ['date' => '2026-10-02', 'reason' => 'x'])->assertSessionHas('error');
    }

    public function test_closed_period_blocks_transactions_and_journals(): void
    {
        $cash = Account::where('code', '1101')->value('id');
        $capital = Account::where('code', '3101')->value('id');

        $this->post(route('journals.store'), [
            'date' => '2026-09-15', 'description' => 'Modal awal',
            'lines' => [['account_id' => $cash, 'debit' => 500000], ['account_id' => $capital, 'credit' => 500000]],
        ])->assertSessionHasNoErrors();

        // Bulan depan belum bisa ditutup; Oktober belum bisa sebelum September
        $this->post(route('periods.close'), ['year' => 2026, 'month' => 11])->assertSessionHasErrors('period');
        $this->post(route('periods.close'), ['year' => 2026, 'month' => 10])->assertSessionHasErrors('period');

        $this->post(route('periods.close'), ['year' => 2026, 'month' => 9])->assertSessionHasNoErrors();
        $this->assertTrue(AccountingPeriod::isLocked(Carbon::parse('2026-09-30')));
        $this->assertFalse(AccountingPeriod::isLocked(Carbon::parse('2026-10-01')));
        $this->get(route('periods.index'))->assertOk()->assertSee('September 2026');

        $this->post(route('journals.store'), [
            'date' => '2026-09-20', 'description' => 'Terlambat',
            'lines' => [['account_id' => $cash, 'debit' => 1000], ['account_id' => $capital, 'credit' => 1000]],
        ])->assertSessionHasErrors('date');

        // Transaksi operasional bertanggal di periode tertutup juga ditolak
        $this->post(route('stock.adjustment.store'), [
            'item' => 'variant:'.$this->variant->id, 'date' => '2026-09-20', 'mode' => 'in', 'quantity' => 1, 'notes' => 'Koreksi',
        ])->assertSessionHasErrors('date');
        $this->assertEquals(100, (float) $this->variant->fresh()->stock);

        // Bangun ulang jurnal ditolak selama ada periode tertutup
        $this->post(route('journals.rebuild'))->assertSessionHasErrors('status');

        $this->post(route('periods.reopen'), ['year' => 2026, 'month' => 9, 'reason' => 'Koreksi'])->assertSessionHasNoErrors();
        $this->assertFalse(AccountingPeriod::isLocked(Carbon::parse('2026-09-20')));
    }

    public function test_rebuild_reproduces_the_same_balances(): void
    {
        $this->purchaseCycle();
        $so = $this->salesCycle();
        $this->post(route('sales-returns.store'), [
            'order_id' => $so->id, 'return_date' => '2026-10-10', 'reason' => 'Cacat', 'items' => [$so->items()->first()->id => 5],
        ])->assertSessionHasNoErrors();
        $invoice = Invoice::first();
        $this->post(route('payments.store'), [
            'type' => 'receipt', 'party_id' => $so->customer_id, 'payment_date' => '2026-10-11', 'method' => 'transfer',
            'allocations' => [$invoice->id => 100000],
        ]);
        $this->post(route('payments.cancel', Payment::where('type', 'receipt')->first()), ['reason' => 'Batal']);

        $snapshot = fn () => Account::where('is_header', false)->orderBy('code')->get()->mapWithKeys(fn ($a) => [$a->code => $this->balance($a->code)])->filter()->all();
        $before = $snapshot();

        $this->post(route('journals.rebuild'))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertEquals($before, $snapshot());
        $this->assertSame(0, JournalEntry::whereNotNull('reversal_of_id')->count());
        $this->assertBooksBalanced();
    }

    public function test_financial_report_pages_and_exports(): void
    {
        $this->salesCycle();
        $account = Account::where('code', '1201')->first();

        $this->get(route('accounts.index'))->assertOk()->assertSee('Piutang Usaha');
        $this->get(route('accounts.create'))->assertOk();
        $this->get(route('accounts.edit', $account))->assertOk();
        $this->get(route('accounts.mapping'))->assertOk()->assertSee('Persediaan barang dalam proses');
        $this->get(route('journals.index', ['from' => '2026-10-01']))->assertOk()->assertSee('INV-202610-0001');
        $this->get(route('journals.show', JournalEntry::where('source_type', 'invoice')->first()))->assertOk();
        $this->get(route('reports.ledger', ['account_id' => $account->id, 'from' => '2026-10-01']))->assertOk()->assertSee('2.131.200');
        $this->get(route('reports.trial-balance', ['from' => '2026-10-01']))->assertOk()->assertSee('seimbang');
        $this->get(route('reports.profit-loss', ['from' => '2026-10-01']))->assertOk()->assertSee('LABA KOTOR');
        $this->get(route('reports.balance-sheet'))->assertOk()->assertSee('Neraca seimbang');
        $this->get(route('invoices.show', Invoice::first()))->assertOk()->assertSee('Jurnal Akuntansi');

        foreach (['reports.ledger', 'reports.trial-balance', 'reports.profit-loss', 'reports.balance-sheet'] as $route) {
            $this->get(route($route, ['export' => 'xlsx', 'account_id' => $account->id]))->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $this->get(route($route, ['export' => 'pdf', 'account_id' => $account->id]))->assertOk()->assertHeader('content-type', 'application/pdf');
        }
    }

    public function test_accounts_can_be_managed_and_mapping_changed(): void
    {
        $parent = Account::where('code', '6000')->first();

        $this->post(route('accounts.store'), [
            'code' => '6110', 'name' => 'Beban Kebersihan', 'type' => 'expense', 'parent_id' => $parent->id, 'is_active' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $account = Account::where('code', '6110')->first();
        $this->assertSame('debit', $account->normal_balance);

        // Induk harus bertipe sama
        $this->post(route('accounts.store'), [
            'code' => '6111', 'name' => 'Salah', 'type' => 'asset', 'parent_id' => $parent->id,
        ])->assertSessionHasErrors('parent_id');

        // Akun sistem tidak dapat dihapus
        $this->delete(route('accounts.destroy', Account::where('code', '1201')->first()))->assertSessionHas('error');
        $this->delete(route('accounts.destroy', $account))->assertRedirect();
        $this->assertNull(Account::where('code', '6110')->first());

        // Ganti akun kas ke bank untuk pembayaran tunai
        $roles = collect(LedgerAccounts::ROLES)->map(fn ($r, $role) => LedgerAccounts::code($role))->all();
        $roles['cash'] = '1102';
        $this->put(route('accounts.mapping.update'), ['roles' => $roles])->assertSessionHasNoErrors();
        $this->assertSame('1102', LedgerAccounts::code('cash'));

        $roles['cash'] = '1100'; // akun header ditolak
        $this->put(route('accounts.mapping.update'), ['roles' => $roles])->assertSessionHasErrors('roles.cash');
    }

    public function test_accounting_permissions_follow_roles(): void
    {
        $finance = User::factory()->role(Role::Keuangan)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        $this->actingAs($finance);
        $this->get(route('journals.index'))->assertOk();
        $this->get(route('reports.balance-sheet'))->assertOk();
        $this->get(route('periods.index'))->assertOk();
        $this->get(route('journals.create'))->assertOk();

        $this->actingAs($sales);
        $this->get(route('journals.index'))->assertForbidden();
        $this->get(route('reports.profit-loss'))->assertForbidden();
        $this->get(route('accounts.index'))->assertForbidden();
        $this->get(route('periods.index'))->assertForbidden();
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Jurnal Umum');
    }
}
