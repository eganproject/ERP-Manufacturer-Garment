<?php

namespace Tests\Feature;

use App\Enums\EmployeeWorkType;
use App\Enums\PayrollStatus;
use App\Enums\ProductionCostType;
use App\Enums\Role;
use App\Models\Account;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Payroll;
use App\Models\PieceworkEntry;
use App\Models\PieceworkSheet;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\LedgerRebuilder;
use App\Services\PayrollService;
use App\Services\PieceworkService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PieceworkPayrollTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Employee $sewer;

    private Employee $fullMaker;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-28 10:00:00');
        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create());
        $this->product = Product::where('code', 'TS-BSC')->with('variants')->first();

        // Tarif: 3 operasi + tarif pcs jadi
        $this->put(route('piece-rates.update', $this->product), [
            'full_garment_rate' => 3500,
            'operations' => [
                ['name' => 'Potong', 'stage' => 'cutting', 'rate' => 500, 'is_active' => 1],
                ['name' => 'Jahit badan & lengan', 'stage' => 'sewing', 'rate' => 2000, 'is_active' => 1],
                ['name' => 'Buang benang & lipat', 'stage' => 'finishing', 'rate' => 500, 'is_active' => 1],
            ],
        ])->assertSessionHasNoErrors();

        $this->post(route('employees.store'), ['code' => 'KRY-001', 'name' => 'Siti', 'work_type' => 'operation', 'is_active' => 1])->assertSessionHasNoErrors();
        $this->post(route('employees.store'), ['code' => 'KRY-002', 'name' => 'Budi', 'work_type' => 'full', 'is_active' => 1])->assertSessionHasNoErrors();
        $this->sewer = Employee::where('code', 'KRY-001')->first();
        $this->fullMaker = Employee::where('code', 'KRY-002')->first();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function op(string $name): int
    {
        return $this->product->operations()->where('name', $name)->value('id');
    }

    /** SPK 20 pcs yang sudah dimulai. */
    private function startedSpk(): ProductionOrder
    {
        $this->post(route('production-orders.store'), [
            'product_id' => $this->product->id,
            'start_date' => '2026-10-26',
            'items' => $this->product->variants->take(2)->mapWithKeys(fn ($v) => [$v->id => 10])->all(),
        ])->assertRedirect();
        $spk = ProductionOrder::latest('id')->first();
        $this->post(route('production-orders.start', $spk))->assertSessionHasNoErrors();

        return $spk->fresh();
    }

    private function record(ProductionOrder $spk, array $lines, string $date = '2026-10-27')
    {
        return $this->post(route('piecework.store'), ['production_order_id' => $spk->id, 'date' => $date, 'lines' => $lines]);
    }

    private function balance(string $code): float
    {
        $id = Account::where('code', $code)->value('id');

        return round((float) JournalLine::where('account_id', $id)->sum('debit') - (float) JournalLine::where('account_id', $id)->sum('credit'), 2);
    }

    public function test_piece_rates_replace_standard_labor_and_work_feeds_spk_cost(): void
    {
        $this->assertEquals(3000, (float) $this->product->operations()->sum('rate'));

        $spk = $this->startedSpk();
        // Artikel borongan: upah standar BOM tidak dibebankan, overhead tetap
        $this->assertSame(0, $spk->costs()->where('type', ProductionCostType::Labor)->count());
        $this->assertSame(1, $spk->costs()->where('type', ProductionCostType::Overhead)->count());

        $this->get(route('piecework.create', ['production_order_id' => $spk->id]))->assertOk()->assertSee('Jahit badan &amp; lengan', false);

        $this->record($spk, [
            ['employee_id' => $this->sewer->id, 'operation' => (string) $this->op('Potong'), 'quantity' => 16],
            ['employee_id' => $this->sewer->id, 'operation' => (string) $this->op('Jahit badan & lengan'), 'quantity' => 8, 'reject_quantity' => 1],
            ['employee_id' => $this->fullMaker->id, 'operation' => 'full', 'quantity' => 4],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $sheet = PieceworkSheet::first();
        $this->assertSame('HKB-202610-0001', $sheet->number);
        // 16×500 + 8×2000 + 4×3500 (reject tidak dibayar)
        $this->assertEquals(38000, (float) $sheet->total_amount);
        $cost = $spk->costs()->where('piecework_sheet_id', $sheet->id)->first();
        $this->assertSame(ProductionCostType::Labor, $cost->type);
        $this->assertEquals(38000, (float) $cost->amount);

        // Jurnal: Dr WIP / Cr Hutang upah produksi
        $this->assertEquals(-38000, $this->balance('2103'));

        // Biaya borongan tidak bisa dihapus manual dari SPK
        $this->delete(route('production-orders.costs.destroy', [$spk, $cost]))->assertSessionHasErrors('status');

        $progress = collect(app(PieceworkService::class)->progress($spk->fresh()));
        $this->assertEquals(20, $progress[(string) $this->op('Potong')]['used']); // 16 operasi + 4 pcs jadi
        $this->assertEquals(7, $progress[(string) $this->op('Jahit badan & lengan')]['remaining']); // 20 − 8 − 1 − 4
        $this->assertEquals(0, $progress['full']['remaining']); // potong sudah habis

        $this->get(route('production-orders.show', $spk))->assertOk()->assertSee('Upah Borongan Karyawan')->assertSee($sheet->number);
        $this->get(route('piecework.show', $sheet))->assertOk()->assertSee('Siti');
        $this->get(route('piecework.index'))->assertOk()->assertSee($sheet->number);
    }

    public function test_validation_of_capacity_employee_type_and_subcontracted_stage(): void
    {
        $spk = $this->startedSpk();

        // Melebihi jumlah SPK
        $this->record($spk, [['employee_id' => $this->sewer->id, 'operation' => (string) $this->op('Potong'), 'quantity' => 21]])
            ->assertSessionHasErrors('lines');

        // Karyawan per operasi tidak boleh dicatat pcs jadi, dan sebaliknya
        $this->record($spk, [['employee_id' => $this->sewer->id, 'operation' => 'full', 'quantity' => 1]])->assertSessionHasErrors('lines.0.operation');
        $this->record($spk, [['employee_id' => $this->fullMaker->id, 'operation' => (string) $this->op('Potong'), 'quantity' => 1]])->assertSessionHasErrors('lines.0.operation');

        // Tanggal di masa depan
        $this->record($spk, [['employee_id' => $this->sewer->id, 'operation' => (string) $this->op('Potong'), 'quantity' => 1]], '2026-11-30')->assertSessionHasErrors('date');

        // Jahit dimaklonkan 15 pcs: karyawan internal hanya bisa menjahit 5 pcs
        $vendor = Supplier::where('code', 'MKL-001')->first();
        $this->post(route('subcontract-orders.store'), [
            'production_order_id' => $spk->id, 'supplier_id' => $vendor->id, 'order_date' => '2026-10-27',
            'stages' => ['sewing'], 'quantity' => 15, 'rate' => 3000, 'materials' => [],
        ])->assertSessionHasNoErrors();

        $this->record($spk, [['employee_id' => $this->sewer->id, 'operation' => (string) $this->op('Jahit badan & lengan'), 'quantity' => 6]])->assertSessionHasErrors('lines');
        $this->record($spk, [['employee_id' => $this->sewer->id, 'operation' => (string) $this->op('Jahit badan & lengan'), 'quantity' => 5]])->assertSessionHasNoErrors();
        $this->record($spk, [['employee_id' => $this->fullMaker->id, 'operation' => 'full', 'quantity' => 1]])->assertSessionHasErrors('lines');

        // SPK draft tidak bisa dicatat
        $this->post(route('production-orders.store'), [
            'product_id' => $this->product->id, 'start_date' => '2026-10-26',
            'items' => [$this->product->variants->first()->id => 5],
        ]);
        $draft = ProductionOrder::latest('id')->first();
        $this->record($draft, [['employee_id' => $this->sewer->id, 'operation' => (string) $this->op('Potong'), 'quantity' => 1]])->assertSessionHasErrors('production_order_id');

        $this->assertSame(1, PieceworkSheet::count());
    }

    public function test_weekly_payroll_with_advance_approval_payment_and_cancellation(): void
    {
        $spk = $this->startedSpk();
        $this->record($spk, [
            ['employee_id' => $this->sewer->id, 'operation' => (string) $this->op('Potong'), 'quantity' => 16],
            ['employee_id' => $this->fullMaker->id, 'operation' => 'full', 'quantity' => 4],
        ], '2026-10-26')->assertSessionHasNoErrors();
        $this->record($spk, [
            ['employee_id' => $this->sewer->id, 'operation' => (string) $this->op('Jahit badan & lengan'), 'quantity' => 10],
        ], '2026-10-28')->assertSessionHasNoErrors();

        // Kasbon Siti Rp 5.000
        $this->post(route('employees.advances.store', $this->sewer), ['date' => '2026-10-27', 'amount' => 5000, 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertEquals(5000, $this->sewer->advanceBalance());
        $this->assertEquals(5000, $this->balance('1203'));

        // Periode default mingguan, mulai Senin
        [$start, $end] = PayrollService::suggestedPeriod();
        $this->assertSame('2026-10-26', $start->toDateString());
        $this->assertSame('2026-11-01', $end->toDateString());
        $this->get(route('payrolls.create'))->assertOk()->assertSee('Siti');

        // Periode sampai 27 Okt hanya mengambil hasil kerja tanggal 26
        $this->post(route('payrolls.store'), ['period_start' => '2026-10-26', 'period_end' => '2026-10-27'])->assertSessionHasNoErrors();
        $payroll = Payroll::first();
        $this->assertSame('UPH-202610-0001', $payroll->number);
        $this->assertEquals(8000 + 14000, (float) $payroll->total_gross);
        $siti = $payroll->lines()->where('employee_id', $this->sewer->id)->first();
        $this->assertEquals(5000, (float) $siti->advance_deduction); // kasbon otomatis dipotong
        $this->assertEquals(3000, (float) $siti->net);
        $this->assertSame(1, PieceworkEntry::whereNull('payroll_line_id')->count());

        // Hasil kerja yang sudah masuk penggajian terkunci
        $this->delete(route('piecework.destroy', PieceworkSheet::first()))->assertSessionHasErrors('status');

        // Hitung ulang dengan periode diperpanjang tetap mempertahankan potongan; ubah bonus & potongan
        $payroll->update(['period_end' => '2026-11-01']);
        $this->post(route('payrolls.recalculate', $payroll))->assertSessionHasNoErrors();
        $siti = $payroll->lines()->where('employee_id', $this->sewer->id)->first();
        $this->assertEquals(28000, (float) $siti->gross);
        $this->assertEquals(5000, (float) $siti->advance_deduction);

        $budi = $payroll->lines()->where('employee_id', $this->fullMaker->id)->first();
        $this->put(route('payrolls.update', $payroll), ['lines' => [
            $siti->id => ['allowance' => 2000, 'advance_deduction' => 6000, 'other_deduction' => 0],
        ]])->assertSessionHasErrors("lines.{$siti->id}.advance_deduction");
        $this->put(route('payrolls.update', $payroll), ['lines' => [
            $siti->id => ['allowance' => 2000, 'advance_deduction' => 5000, 'other_deduction' => 1000, 'notes' => 'Denda telat'],
            $budi->id => ['allowance' => 0, 'advance_deduction' => 0, 'other_deduction' => 0],
        ]])->assertSessionHasNoErrors();
        $payroll->refresh();
        $this->assertEquals(42000, (float) $payroll->total_gross);
        $this->assertEquals(2000, (float) $payroll->total_allowance);
        $this->assertEquals(6000, (float) $payroll->total_deduction);
        $this->assertEquals(38000, (float) $payroll->total_net);

        // Belum disetujui tidak bisa dibayar
        $this->post(route('payrolls.pay', $payroll), ['payment_date' => '2026-10-31', 'payment_method' => 'cash'])->assertSessionHasErrors('status');

        $this->get(route('payrolls.show', $payroll))->assertOk()->assertSee('Siti');
        $this->get(route('payrolls.pdf', $payroll))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('payrolls.slips', $payroll))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('payrolls.slip', [$payroll, $siti]))->assertOk();

        $this->post(route('payrolls.approve', $payroll))->assertSessionHasNoErrors();
        $this->assertSame(PayrollStatus::Approved, $payroll->fresh()->status);
        $this->put(route('payrolls.update', $payroll), ['lines' => [$siti->id => ['allowance' => 0]]])->assertSessionHasErrors('status');

        $this->post(route('payrolls.pay', $payroll), ['payment_date' => '2026-10-31', 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $payroll->refresh();
        $this->assertSame(PayrollStatus::Paid, $payroll->status);

        // Hutang upah lunas, kasbon lunas, kas keluar = net + kasbon
        $this->assertEquals(0, $this->balance('2103'));
        $this->assertEquals(0, $this->balance('1203'));
        $this->assertEquals(2000, $this->balance('5205'));
        $this->assertEquals(-1000, $this->balance('7199'));
        $this->assertEqualsWithDelta((float) JournalLine::sum('debit'), (float) JournalLine::sum('credit'), 0.001);
        $this->assertEquals(0, $this->sewer->advanceBalance());

        // Kasbon yang sudah dipotong tidak bisa dibatalkan
        $advance = $this->sewer->advances()->first();
        $this->post(route('employee-advances.cancel', $advance))->assertSessionHasErrors('status');

        // Rebuild jurnal menghasilkan saldo yang sama
        $before = [$this->balance('1101'), $this->balance('1302'), $this->balance('2103')];
        app(LedgerRebuilder::class)->rebuild();
        $this->assertEquals($before, [$this->balance('1101'), $this->balance('1302'), $this->balance('2103')]);

        // Batalkan pembayaran: jurnal dibalik, hasil kerja dilepas
        $this->post(route('payrolls.cancel', $payroll), ['reason' => 'salah hitung'])->assertSessionHasNoErrors();
        $this->assertSame(PayrollStatus::Cancelled, $payroll->fresh()->status);
        $this->assertEquals(-42000, $this->balance('2103'));
        $this->assertSame(3, PieceworkEntry::whereNull('payroll_line_id')->count());
        $this->assertEquals(5000, $this->sewer->advanceBalance());
        $this->assertTrue(JournalEntry::where('source_type', 'payroll')->whereNotNull('reversal_of_id')->exists());

        $this->post(route('employee-advances.cancel', $advance))->assertSessionHasNoErrors();
        $this->assertEquals(0, $this->balance('1203'));

        $this->get(route('reports.labor', ['from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk()->assertSee('Siti');
        $this->get(route('reports.labor', ['from' => '2026-10-01', 'to' => '2026-10-31', 'view' => 'orders']))->assertOk()->assertSee($spk->number);
        $this->get(route('reports.labor', ['from' => '2026-10-01', 'to' => '2026-10-31', 'export' => 'xlsx']))->assertOk();
    }

    public function test_deleting_sheet_reverses_cost_and_completion_uses_piecework_in_hpp(): void
    {
        $spk = $this->startedSpk();
        $this->record($spk, [['employee_id' => $this->sewer->id, 'operation' => (string) $this->op('Potong'), 'quantity' => 2]])->assertSessionHasNoErrors();
        $sheet = PieceworkSheet::first();

        $this->delete(route('piecework.destroy', $sheet))->assertRedirect(route('production-orders.show', $spk));
        $this->assertSame(0, PieceworkSheet::count());
        $this->assertSame(0, $spk->costs()->where('type', ProductionCostType::Labor)->count());
        $this->assertEquals(0, $this->balance('2103'));

        $this->record($spk, [['employee_id' => $this->fullMaker->id, 'operation' => 'full', 'quantity' => 20]])->assertSessionHasNoErrors();

        foreach ($spk->stages as $stage) {
            $this->put(route('production-orders.stages.update', [$spk, $stage]), ['status' => 'done', 'output_quantity' => 20])->assertSessionHasNoErrors();
        }

        $this->post(route('production-orders.complete', $spk), ['finished' => $spk->items->mapWithKeys(fn ($i) => [$i->id => 10])->all()])->assertSessionHasNoErrors();
        $spk->refresh();
        $this->assertEquals(70000 + 20 * 2500, (float) $spk->conversion_cost); // borongan 20×3500 + overhead standar
    }

    public function test_settings_change_payroll_cycle(): void
    {
        Setting::put(['payroll_cycle' => 'biweekly', 'payroll_week_start' => 6]);
        [$start, $end] = PayrollService::suggestedPeriod();
        $this->assertSame('2026-10-24', $start->toDateString()); // Sabtu
        $this->assertSame('2026-11-06', $end->toDateString());

        Setting::put(['payroll_cycle' => 'monthly']);
        [$start, $end] = PayrollService::suggestedPeriod();
        $this->assertSame('2026-10-01', $start->toDateString());
        $this->assertSame('2026-10-31', $end->toDateString());

        $this->get(route('settings.edit'))->assertOk()->assertSee('Siklus Penggajian');
    }

    public function test_pages_render_and_roles(): void
    {
        foreach ([route('employees.index'), route('employees.create'), route('employees.show', $this->sewer), route('employees.edit', $this->sewer),
            route('piece-rates.index'), route('piece-rates.edit', $this->product), route('piecework.index'), route('piecework.create'),
            route('payrolls.index'), route('payrolls.create'), route('reports.labor'), route('products.show', $this->product)] as $url) {
            $this->get($url)->assertOk();
        }

        $produksi = User::factory()->role(Role::Produksi)->create();
        $keuangan = User::factory()->role(Role::Keuangan)->create();
        $sales = User::factory()->role(Role::Sales)->create();

        $this->actingAs($produksi)->get(route('piecework.create'))->assertOk();
        $this->actingAs($produksi)->get(route('piece-rates.index'))->assertOk();
        $this->actingAs($produksi)->post(route('employees.advances.store', $this->sewer), ['date' => '2026-10-27', 'amount' => 1000, 'payment_method' => 'cash'])->assertForbidden();

        $this->actingAs($keuangan)->get(route('payrolls.index'))->assertOk();
        $this->actingAs($keuangan)->get(route('piecework.create'))->assertForbidden();
        $this->actingAs($keuangan)->post(route('employees.advances.store', $this->sewer), ['date' => '2026-10-27', 'amount' => 1000, 'payment_method' => 'cash'])->assertSessionHasNoErrors();

        $this->actingAs($sales)->get(route('payrolls.index'))->assertForbidden();
        $this->actingAs($sales)->get(route('employees.index'))->assertForbidden();
        $this->assertSame(EmployeeWorkType::Operation, $this->sewer->work_type);
    }
}
