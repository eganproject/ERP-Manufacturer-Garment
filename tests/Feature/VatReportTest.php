<?php

namespace Tests\Feature;

use App\Enums\ReturnStatus;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Models\VatPeriod;
use App\Services\Accounting\LedgerRebuilder;
use App\Services\Reports\VatReport;
use App\Services\StockService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VatReportTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create());

        $this->variant = Product::where('code', 'TS-BSC')->with('variants')->first()->variants->first();
        DB::transaction(function () {
            $this->variant->forceFill(['stock' => 0, 'average_cost' => 0])->save();
            app(StockService::class)->move($this->variant, 200, StockMovementType::Adjustment, unitCost: 45000);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function balance(string $code): float
    {
        $id = Account::where('code', $code)->value('id');

        return round((float) JournalLine::where('account_id', $id)->sum(DB::raw('debit - credit')), 2);
    }

    /** SO + kirim + invoice ber-PPN 11%. */
    private function invoiced(float $qty, string $date, float $discount = 0): Invoice
    {
        $this->post(route('sales-orders.store'), [
            'customer_id' => Customer::first()->id, 'order_date' => $date, 'discount' => $discount,
            'items' => [['product_variant_id' => $this->variant->id, 'quantity' => $qty, 'price' => 65000]],
        ])->assertSessionHasNoErrors();
        $so = SalesOrder::latest('id')->first();
        $this->post(route('sales-orders.confirm', $so));
        $this->post(route('sales-orders.deliver.store', $so), ['delivery_date' => $date, 'items' => [$so->items()->first()->id => $qty]])->assertSessionHasNoErrors();
        $this->post(route('invoices.store'), [
            'sales_order_id' => $so->id, 'invoice_date' => $date, 'tax_rate' => 11,
            'items' => [$so->items()->first()->id => $qty],
        ])->assertSessionHasNoErrors();

        return Invoice::latest('id')->first();
    }

    /** PO 40 m kain @125.000 + tagihan ber-PPN 11% dengan nomor faktur pajak. */
    private function billed(string $date): Bill
    {
        $this->post(route('purchase-orders.store'), [
            'supplier_id' => Supplier::first()->id, 'order_date' => $date,
            'items' => [['material_id' => Material::where('code', 'KN-CMB30-HTM')->first()->id, 'quantity' => 40, 'price' => 125000]],
        ]);
        $po = PurchaseOrder::latest('id')->first();
        $this->post(route('purchase-orders.submit', $po));
        $this->post(route('purchase-orders.receive.store', $po), ['receipt_date' => $date, 'items' => [$po->items()->first()->id => 40]]);
        $this->post(route('bills.store'), [
            'purchase_order_id' => $po->id, 'bill_date' => $date, 'tax_rate' => 11,
            'tax_invoice_number' => '010.000-26.00001234', 'tax_invoice_date' => $date,
            'items' => [$po->items()->first()->id => ['quantity' => 40, 'price' => 125000]],
        ])->assertSessionHasNoErrors();

        return Bill::latest('id')->first();
    }

    public function test_vat_report_nets_output_input_and_returns(): void
    {
        $invoice = $this->invoiced(30, '2026-10-02', 32500); // DPP 1.950.000 − 32.500 = 1.917.500, PPN 210.925
        $so = $invoice->salesOrder;
        $this->post(route('sales-returns.store'), [
            'order_id' => $so->id, 'return_date' => '2026-10-10', 'reason' => 'Cacat jahit',
            'items' => [$so->items()->first()->id => 5],
        ])->assertSessionHasNoErrors();
        $bill = $this->billed('2026-10-04');
        $this->invoiced(10, '2026-11-03'); // masa berikutnya, tidak ikut Oktober

        $this->assertSame('010.000-26.00001234', $bill->tax_invoice_number);

        $data = app(VatReport::class)->build(Carbon::parse('2026-10-01'));
        $return = SalesReturn::first();

        $this->assertCount(2, $data['output']);
        $this->assertCount(1, $data['input']);
        $this->assertEquals(round(210925 - (float) $return->tax_amount, 2), $data['totals']['output']);
        $this->assertEquals(550000, $data['totals']['input']);
        $this->assertEquals(1, $data['totals']['missing_output']);
        $this->assertEquals(0, $data['totals']['missing_input']);
        $this->assertEquals(round($data['totals']['output'] - 550000, 2), $data['totals']['net']);
        $this->assertNull($data['canClose']);

        $this->get(route('reports.vat', ['period' => '2026-10']))->assertOk()
            ->assertSee($invoice->number)->assertSee($bill->number)->assertSee('010.000-26.00001234')->assertSee('Belum diisi');
        $this->get(route('reports.vat', ['period' => '2026-10', 'export' => 'xlsx']))->assertOk();
        $this->get(route('reports.vat', ['period' => '2026-10', 'export' => 'pdf']))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_tax_invoice_numbers_are_validated_and_unique(): void
    {
        $first = $this->invoiced(10, '2026-10-02');
        $second = $this->invoiced(10, '2026-10-03');

        $this->put(route('invoices.tax-invoice', $first), ['tax_invoice_number' => '12-34'])->assertSessionHasErrors('tax_invoice_number');
        $this->put(route('invoices.tax-invoice', $first), ['tax_invoice_number' => '04002600000000123', 'tax_invoice_date' => '2026-10-02'])->assertSessionHasNoErrors();
        $this->assertSame('04002600000000123', $first->fresh()->tax_invoice_number);
        $this->put(route('invoices.tax-invoice', $second), ['tax_invoice_number' => '04002600000000123'])->assertSessionHasErrors('tax_invoice_number');

        $this->get(route('invoices.show', $first))->assertOk()->assertSee('04002600000000123');
        $this->assertDatabaseHas('activity_logs', ['subject_type' => 'invoice', 'subject_id' => $first->id, 'event' => 'tax_invoice']);

        // Sales tidak boleh mengisi faktur pajak / melihat laporan PPN, Keuangan boleh
        $this->actingAs(User::factory()->role(Role::Sales)->create());
        $this->put(route('invoices.tax-invoice', $second), ['tax_invoice_number' => '04002600000000124'])->assertForbidden();
        $this->get(route('reports.vat'))->assertForbidden();
        $this->actingAs(User::factory()->role(Role::Keuangan)->create());
        $this->put(route('invoices.tax-invoice', $second), ['tax_invoice_number' => '04002600000000124'])->assertSessionHasNoErrors();
        $this->get(route('reports.vat'))->assertOk();
    }

    public function test_closing_periods_posts_journal_and_carries_overpayment(): void
    {
        $this->invoiced(30, '2026-10-02'); // PPN keluaran 214.500
        $this->billed('2026-10-04'); // PPN masukan 550.000

        // Masa berjalan belum bisa ditutup
        $this->post(route('vat-periods.store'), ['period' => '2026-11'])->assertSessionHasErrors('period');

        $this->post(route('vat-periods.store'), ['period' => '2026-10', 'notes' => 'SPT Okt'])->assertSessionHasNoErrors();
        $october = VatPeriod::first();
        $this->assertSame('PPN-202610-0001', $october->number);
        $this->assertEquals(214500, (float) $october->output_tax);
        $this->assertEquals(550000, (float) $october->input_tax);
        $this->assertEquals(-335500, (float) $october->net);
        $this->assertEquals(0, $this->balance('2105'));
        $this->assertEquals(0, $this->balance('1401'));
        $this->assertEquals(335500, $this->balance('1405')); // lebih bayar dikompensasikan

        // Tidak bisa ditutup dua kali
        $this->post(route('vat-periods.store'), ['period' => '2026-10'])->assertSessionHasErrors('period');

        // November: keluaran 100 × 65.000 × 11% = 715.000 − kompensasi 335.500 = kurang bayar 379.500
        Carbon::setTestNow('2026-12-05 09:00:00');
        $this->invoiced(100, '2026-11-05');
        $data = app(VatReport::class)->build(Carbon::parse('2026-11-01'));
        $this->assertEquals(335500, $data['totals']['compensation']);
        $this->assertEquals(379500, $data['totals']['net']);

        $this->post(route('vat-periods.store'), ['period' => '2026-11'])->assertSessionHasNoErrors();
        $november = VatPeriod::latest('id')->first();
        $this->assertEquals(379500, (float) $november->net);
        $this->assertEquals(0, $this->balance('1405'));
        $this->assertEquals(-379500, $this->balance('2109')); // Hutang PPN (kredit)
        $this->assertEquals(0, $this->balance('2105'));

        // Rebuild ledger menghasilkan saldo yang sama
        app(LedgerRebuilder::class)->rebuild();
        $this->assertEquals(-379500, $this->balance('2109'));
        $this->assertEquals(0, $this->balance('1405'));

        // Hanya masa terakhir yang bisa dibatalkan
        $this->post(route('vat-periods.cancel', $october), ['reason' => 'salah'])->assertSessionHasErrors('status');
        $this->post(route('vat-periods.cancel', $november), ['reason' => 'pembetulan'])->assertSessionHasNoErrors();
        $this->assertSame(ReturnStatus::Cancelled, $november->fresh()->status);
        $this->assertEquals(0, $this->balance('2109'));
        $this->assertEquals(335500, $this->balance('1405'));

        // Berurutan: Desember tidak bisa ditutup sebelum November
        Carbon::setTestNow('2027-01-05 09:00:00');
        $this->post(route('vat-periods.store'), ['period' => '2026-12'])->assertSessionHasErrors('period');

        $this->get(route('reports.vat', ['period' => '2026-11']))->assertOk()->assertSee('PPN-202611-0001')->assertSee('Tutup Masa November 2026');
    }
}
