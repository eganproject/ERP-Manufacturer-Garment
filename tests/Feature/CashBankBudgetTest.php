<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Models\Account;
use App\Models\Budget;
use App\Models\CashTransaction;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Product;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Accounting\BudgetReport;
use App\Services\Accounting\CashFlowReport;
use App\Services\Accounting\LedgerRebuilder;
use App\Services\StockService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CashBankBudgetTest extends TestCase
{
    use RefreshDatabase;

    private Account $kas;

    private Account $bank;

    private Account $bca;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-25 10:00:00');
        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create());

        $variant = Product::where('code', 'TS-BSC')->with('variants')->first()->variants->first();
        DB::transaction(function () use ($variant) {
            $variant->forceFill(['stock' => 0, 'average_cost' => 0])->save();
            app(StockService::class)->move($variant, 100, StockMovementType::Adjustment, notes: 'Saldo awal', unitCost: 40000);
        });
        app(LedgerRebuilder::class)->rebuild();

        $this->kas = Account::where('code', '1101')->first();
        $this->bank = Account::where('code', '1102')->first();

        // Rekening bank kedua lewat form bagan akun
        $this->post(route('accounts.store'), [
            'code' => '1103', 'name' => 'Bank BCA', 'type' => 'asset', 'parent_id' => Account::where('code', '1100')->value('id'),
            'is_active' => 1, 'is_cash' => 1, 'bank_name' => 'BCA', 'bank_account_number' => '123-456', 'bank_account_holder' => 'PT Garmen',
        ])->assertSessionHasNoErrors();
        $this->bca = Account::where('code', '1103')->first();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function balance(Account|string $account): float
    {
        $id = $account instanceof Account ? $account->id : Account::where('code', $account)->value('id');

        return round((float) JournalLine::where('account_id', $id)->sum('debit') - (float) JournalLine::where('account_id', $id)->sum('credit'), 2);
    }

    private function cash(string $type, array $data, array $lines = []): CashTransaction
    {
        $this->post(route('cash-transactions.store'), ['type' => $type, 'date' => '2026-10-05', 'description' => 'Tes'] + $data + ['lines' => $lines])
            ->assertSessionHasNoErrors();

        return CashTransaction::latest('id')->first();
    }

    public function test_cash_in_out_and_transfer_post_journals_and_can_be_cancelled(): void
    {
        $this->assertTrue($this->bca->is_cash);
        $this->assertSame('123-456', $this->bca->bank_account_number);

        $modal = $this->cash('in', ['cash_account_id' => $this->bca->id, 'party_name' => 'Pemilik'], [
            ['account_id' => Account::where('code', '3101')->value('id'), 'amount' => 50000000],
        ]);
        $this->assertSame('KM-202610-0001', $modal->number);
        $this->assertEquals(50000000, $this->balance($this->bca));
        $this->assertEquals(-50000000, $this->balance('3101'));

        $this->cash('transfer', ['cash_account_id' => $this->bca->id, 'to_account_id' => $this->kas->id, 'amount' => 5000000]);
        $this->assertEquals(45000000, $this->balance($this->bca));
        $this->assertEquals(5000000, $this->balance($this->kas));

        $out = $this->cash('out', ['cash_account_id' => $this->kas->id, 'party_name' => 'PLN'], [
            ['account_id' => Account::where('code', '5201')->value('id'), 'amount' => 1200000, 'description' => 'Listrik pabrik'],
            ['account_id' => Account::where('code', '6103')->value('id'), 'amount' => 300000],
        ]);
        $this->assertSame('KK-202610-0001', $out->number);
        $this->assertEquals(1500000, (float) $out->amount);
        $this->assertEquals(3500000, $this->balance($this->kas));
        $this->assertEquals(1200000, $this->balance('5201'));

        // Akun kas tidak boleh jadi baris lawan; transfer ke rekening yang sama ditolak
        $this->post(route('cash-transactions.store'), ['type' => 'out', 'date' => '2026-10-05', 'description' => 'x', 'cash_account_id' => $this->kas->id,
            'lines' => [['account_id' => $this->bank->id, 'amount' => 1000]]])->assertSessionHasErrors('lines.0.account_id');
        $this->post(route('cash-transactions.store'), ['type' => 'transfer', 'date' => '2026-10-05', 'description' => 'x', 'cash_account_id' => $this->kas->id,
            'to_account_id' => $this->kas->id, 'amount' => 1000])->assertSessionHasErrors('to_account_id');

        // Batal → jurnal dibalik
        $this->post(route('cash-transactions.cancel', $out), ['reason' => 'salah input'])->assertSessionHasNoErrors();
        $this->assertTrue($out->fresh()->isCancelled());
        $this->assertEquals(5000000, $this->balance($this->kas));
        $this->assertEquals(0, $this->balance('5201'));

        // Bangun ulang jurnal menghasilkan saldo yang sama (bukti batal dilewati)
        app(LedgerRebuilder::class)->rebuild();
        $this->assertEquals(45000000, $this->balance($this->bca));
        $this->assertEquals(5000000, $this->balance($this->kas));
        $this->assertEquals(0, $this->balance('5201'));
        $this->assertEqualsWithDelta((float) JournalLine::sum('debit'), (float) JournalLine::sum('credit'), 0.001);

        foreach ([route('cash-bank.index'), route('cash-transactions.index'), route('cash-transactions.show', $modal), route('cash-transactions.show', $out),
            route('cash-transactions.create', ['type' => 'in']), route('cash-transactions.create', ['type' => 'out']), route('cash-transactions.create', ['type' => 'transfer']),
            route('cash-bank.statement', $this->bca), route('accounts.edit', $this->bca), route('accounts.create', ['is_cash' => 1])] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get(route('cash-transactions.pdf', $modal))->assertOk();
        $this->get(route('cash-bank.statement', ['account' => $this->bca, 'export' => 'xlsx']))->assertOk();
        $this->get(route('cash-bank.statement', Account::where('code', '1201')->first()))->assertNotFound();
    }

    public function test_payment_can_use_selected_bank_account(): void
    {
        $so = $this->salesCycle();
        $invoice = Invoice::first();

        $this->post(route('payments.store'), [
            'type' => 'receipt', 'party_id' => $so->customer_id, 'payment_date' => '2026-10-10', 'method' => 'transfer',
            'cash_account_id' => $this->bca->id, 'allocations' => [$invoice->id => (float) $invoice->total],
        ])->assertSessionHasNoErrors();

        $this->assertEquals((float) $invoice->total, $this->balance($this->bca));
        $this->assertEquals(0, $this->balance($this->bank));

        // Akun non-kas ditolak
        $this->post(route('payments.store'), [
            'type' => 'receipt', 'party_id' => $so->customer_id, 'payment_date' => '2026-10-10', 'method' => 'transfer',
            'cash_account_id' => Account::where('code', '1201')->value('id'), 'allocations' => [$invoice->id => 1],
        ])->assertSessionHasErrors('cash_account_id');

        app(LedgerRebuilder::class)->rebuild();
        $this->assertEquals((float) $invoice->total, $this->balance($this->bca));
    }

    public function test_cash_flow_direct_and_indirect_reconcile_to_cash_change(): void
    {
        // Saldo awal kas September (pendanaan, sebelum periode)
        $this->post(route('cash-transactions.store'), ['type' => 'in', 'date' => '2026-09-30', 'description' => 'Modal awal', 'cash_account_id' => $this->bank->id,
            'lines' => [['account_id' => Account::where('code', '3101')->value('id'), 'amount' => 20000000]]])->assertSessionHasNoErrors();

        $so = $this->salesCycle();
        $invoice = Invoice::first();
        $this->post(route('payments.store'), [
            'type' => 'receipt', 'party_id' => $so->customer_id, 'payment_date' => '2026-10-10', 'method' => 'cash',
            'allocations' => [$invoice->id => 1000000],
        ])->assertSessionHasNoErrors();

        $this->cash('out', ['cash_account_id' => $this->bank->id], [['account_id' => Account::where('code', '1503')->value('id'), 'amount' => 8000000]]);   // beli mesin
        $this->cash('out', ['cash_account_id' => $this->bank->id], [['account_id' => Account::where('code', '6102')->value('id'), 'amount' => 2500000]]);   // sewa
        $this->cash('in', ['cash_account_id' => $this->bca->id], [['account_id' => Account::where('code', '2201')->value('id'), 'amount' => 10000000]]);     // pinjaman bank
        $this->cash('transfer', ['cash_account_id' => $this->bca->id, 'to_account_id' => $this->kas->id, 'amount' => 1000000]);

        $from = Carbon::parse('2026-10-01');
        $to = Carbon::parse('2026-10-31');
        $report = app(CashFlowReport::class);
        $direct = $report->build($from, $to, 'direct');
        $indirect = $report->build($from, $to, 'indirect');

        $this->assertEquals(20000000, $direct['opening']);
        $this->assertEquals(20000000 + 1000000 - 8000000 - 2500000 + 10000000, $direct['closing']);
        $this->assertEquals(500000, $direct['net_change']);

        $this->assertTrue($direct['balanced']);
        $this->assertEquals(1000000 - 2500000, $direct['sections']['operating']['total']);
        $this->assertEquals(-8000000, $direct['sections']['investing']['total']);
        $this->assertEquals(10000000, $direct['sections']['financing']['total']);
        $this->assertSame('Penerimaan dari pelanggan', $direct['sections']['operating']['items']->firstWhere('code', '1201')['name']);

        $this->assertTrue($indirect['balanced']);
        $this->assertEquals($direct['sections']['investing']['total'], $indirect['sections']['investing']['total']);
        $this->assertEquals($direct['sections']['financing']['total'], $indirect['sections']['financing']['total']);
        $this->assertEquals($direct['sections']['operating']['total'], $indirect['sections']['operating']['total']);
        $this->assertSame('Laba (rugi) bersih', $indirect['sections']['operating']['items']->first()['name']);

        // Kelompok arus kas dapat diubah per akun
        Account::where('code', '2201')->update(['cash_flow_activity' => 'operating']);
        $changed = $report->build($from, $to, 'direct');
        $this->assertEquals(0, $changed['sections']['financing']['total']);
        $this->assertTrue($changed['balanced']);

        foreach (['direct', 'indirect'] as $method) {
            $this->get(route('reports.cash-flow', ['from' => '2026-10-01', 'to' => '2026-10-31', 'method' => $method]))->assertOk()->assertSee('Arus Kas');
            $this->get(route('reports.cash-flow', ['method' => $method, 'export' => 'xlsx']))->assertOk();
        }
        $this->get(route('reports.cash-flow', ['export' => 'pdf']))->assertOk();
    }

    public function test_budget_vs_actual(): void
    {
        $sales = Account::where('code', '4101')->value('id');
        $rent = Account::where('code', '6102')->value('id');
        $returns = Account::where('code', '4102')->value('id');
        $months = fn (float $each) => array_fill(0, 12, $each);

        $this->post(route('budgets.store'), [
            'name' => 'Anggaran 2026', 'year' => 2026,
            'lines' => [
                ['account_id' => $sales, 'months' => $months(2000000)],
                ['account_id' => $rent, 'months' => $months(2000000)],
                ['account_id' => $returns, 'months' => $months(100000)],
                ['account_id' => '', 'months' => $months(0)],
            ],
        ])->assertSessionHasNoErrors();
        $budget = Budget::first();
        $this->assertSame(36, $budget->lines()->count());

        // Duplikat akun ditolak
        $this->post(route('budgets.store'), ['name' => 'Dobel', 'year' => 2026, 'lines' => [
            ['account_id' => $rent, 'months' => $months(1)], ['account_id' => $rent, 'months' => $months(1)],
        ]])->assertSessionHasErrors('lines.1.account_id');

        $this->salesCycle(); // penjualan Oktober 1.950.000, potongan 30.000, HPP 1.200.000
        $this->cash('out', ['cash_account_id' => $this->kas->id], [['account_id' => $rent, 'amount' => 2500000]]);

        $data = app(BudgetReport::class)->compare($budget, 10, 10);
        $revenue = $data['groups']['revenue'];
        $salesRow = $revenue['rows']->firstWhere('code', '4101');
        $this->assertEquals(2000000, $salesRow['budget']);
        $this->assertEquals(1950000, $salesRow['actual']);
        $this->assertFalse($salesRow['favorable']);
        // Pendapatan bersih: anggaran 2.000.000 − retur 100.000; realisasi 1.950.000 − potongan 30.000 (di luar anggaran)
        $this->assertEquals(1900000, $revenue['budget']);
        $this->assertEquals(1920000, $revenue['actual']);
        $this->assertTrue($revenue['rows']->firstWhere('code', '4102')['favorable']); // retur di bawah anggaran = baik

        $rentRow = $data['groups']['expense']['rows']->firstWhere('code', '6102');
        $this->assertEquals(2500000, $rentRow['actual']);
        $this->assertFalse($rentRow['favorable']);
        $this->assertEquals(125.0, $rentRow['percent']);

        // HPP tidak dianggarkan tetapi tetap tampil
        $this->assertEquals(1200000, $data['groups']['cost_of_sales']['rows']->firstWhere('code', '5101')['actual']);
        $this->assertEquals(1900000 - 2000000, $data['profit']['budget']);
        $this->assertEquals(1920000 - 1200000 - 2500000, $data['profit']['actual']);
        $this->assertEquals(-100000, $data['months'][1]['budget_profit']);
        $this->assertEquals(1920000 - 1200000 - 2500000, $data['months'][10]['actual_profit']);
        $this->assertNull($data['months'][11]['actual_profit']);

        // Draf anggaran dari realisasi tahun 2026 + 10%
        $this->get(route('budgets.create', ['year' => 2027, 'actual_year' => 2026, 'growth' => 10]))->assertOk()->assertSee('2750000');
        $this->get(route('budgets.create', ['copy' => $budget->id, 'year' => 2027]))->assertOk();

        foreach ([route('budgets.index'), route('budgets.create'), route('budgets.edit', $budget), route('budgets.show', $budget),
            route('budgets.show', [$budget, 'from_month' => 1, 'to_month' => 12])] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get(route('budgets.show', [$budget, 'export' => 'xlsx']))->assertOk();
        $this->get(route('budgets.show', [$budget, 'export' => 'pdf']))->assertOk();

        $this->put(route('budgets.update', $budget), ['name' => 'Anggaran 2026 Rev', 'year' => 2026, 'lines' => [['account_id' => $rent, 'months' => $months(3000000)]]])
            ->assertSessionHasNoErrors();
        $this->assertSame(12, $budget->lines()->count());
        $this->delete(route('budgets.destroy', $budget))->assertRedirect(route('budgets.index'));
        $this->assertSame(0, Budget::count());
    }

    public function test_permissions(): void
    {
        $sales = User::factory()->role(Role::Sales)->create();
        $this->actingAs($sales);
        $this->get(route('cash-bank.index'))->assertForbidden();
        $this->get(route('budgets.index'))->assertForbidden();
        $this->get(route('reports.cash-flow'))->assertForbidden();

        $finance = User::factory()->role(Role::Keuangan)->create();
        $this->actingAs($finance);
        $this->get(route('cash-bank.index'))->assertOk();
        $this->get(route('cash-transactions.create'))->assertOk();
        $this->get(route('budgets.create'))->assertOk();
        $this->get(route('reports.cash-flow'))->assertOk();
    }

    /** SO 50 pcs @65.000 diskon 50.000; kirim & tagih 30 pcs (HPP 40.000) PPN 11%. */
    private function salesCycle(): SalesOrder
    {
        $variant = Product::where('code', 'TS-BSC')->with('variants')->first()->variants->first();
        $this->post(route('sales-orders.store'), [
            'customer_id' => Customer::first()->id,
            'order_date' => '2026-10-01',
            'discount' => 50000,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 50, 'price' => 65000]],
        ]);
        $so = SalesOrder::latest('id')->first();
        $this->post(route('sales-orders.confirm', $so));
        $item = $so->items()->first();
        $this->post(route('sales-orders.deliver.store', $so), ['delivery_date' => '2026-10-02', 'items' => [$item->id => 30]])->assertSessionHasNoErrors();
        $this->post(route('invoices.store'), ['sales_order_id' => $so->id, 'invoice_date' => '2026-10-02', 'tax_rate' => 11, 'items' => [$item->id => 30]])
            ->assertSessionHasNoErrors();

        $this->assertNotNull(JournalEntry::where('source_type', 'invoice')->first());

        return $so->fresh();
    }
}
