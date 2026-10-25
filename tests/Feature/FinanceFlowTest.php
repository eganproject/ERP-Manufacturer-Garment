<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Models\Bill;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Material;
use App\Models\Payment;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AgingReport;
use App\Services\StockService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FinanceFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    /** SO 50 pcs @65.000 diskon 50.000, sudah dikonfirmasi; 30 pcs terkirim. */
    private function deliveredSalesOrder(): SalesOrder
    {
        $variant = Product::where('code', 'TS-BSC')->with('variants')->first()->variants->first();
        DB::transaction(fn () => app(StockService::class)->move($variant, 100, StockMovementType::Adjustment));

        $customer = Customer::first();
        $customer->update(['payment_terms' => 14]);

        $this->post(route('sales-orders.store'), [
            'customer_id' => $customer->id,
            'order_date' => '2026-10-01',
            'discount' => 50000,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 50, 'price' => 65000]],
        ]);
        $so = SalesOrder::first();
        $this->post(route('sales-orders.confirm', $so));
        $this->post(route('sales-orders.deliver.store', $so), ['delivery_date' => '2026-10-02', 'items' => [$so->items()->first()->id => 30]]);

        return $so->fresh();
    }

    private function receivedPurchaseOrder(): PurchaseOrder
    {
        $material = Material::where('code', 'KN-CMB30-HTM')->first();

        $this->post(route('purchase-orders.store'), [
            'supplier_id' => Supplier::first()->id,
            'order_date' => '2026-10-01',
            'items' => [['material_id' => $material->id, 'quantity' => 100, 'price' => 120000]],
        ]);
        $po = PurchaseOrder::first();
        $this->post(route('purchase-orders.submit', $po));
        $this->post(route('purchase-orders.receive.store', $po), ['receipt_date' => '2026-10-03', 'items' => [$po->items()->first()->id => 60]]);

        return $po->fresh();
    }

    public function test_invoice_only_bills_delivered_goods_and_tracks_payments(): void
    {
        Setting::put(['invoice_tax_rate' => 11, 'invoice_bank_info' => 'BCA 123 a.n. CV Garmen']);
        $so = $this->deliveredSalesOrder();
        $item = $so->items()->first();

        $this->get(route('invoices.create'))->assertOk()->assertSee($so->number);
        $this->get(route('invoices.create', ['sales_order_id' => $so->id]))->assertOk();

        // Tidak boleh menagih melebihi barang terkirim (30)
        $this->post(route('invoices.store'), [
            'sales_order_id' => $so->id, 'invoice_date' => '2026-10-02', 'tax_rate' => 11,
            'items' => [$item->id => 31],
        ])->assertSessionHasErrors("items.{$item->id}");

        $this->post(route('invoices.store'), [
            'sales_order_id' => $so->id, 'invoice_date' => '2026-10-02', 'tax_rate' => 11,
            'items' => [$item->id => 30],
        ])->assertRedirect();

        $invoice = Invoice::first();
        $this->assertSame('INV-202610-0001', $invoice->number);
        // 30 x 65.000 = 1.950.000; diskon proporsional 50.000 x 30/50 = 30.000; PPN 11% dari 1.920.000 = 211.200
        $this->assertEquals(1950000, (float) $invoice->subtotal);
        $this->assertEquals(30000, (float) $invoice->discount);
        $this->assertEquals(211200, (float) $invoice->tax_amount);
        $this->assertEquals(2131200, (float) $invoice->total);
        $this->assertSame('2026-10-16', $invoice->due_date->toDateString()); // termin 14 hari
        $this->assertEquals(30, (float) $item->fresh()->invoiced_quantity);
        $this->assertFalse($so->fresh()->load('items')->canInvoice());

        // Pembayaran sebagian lalu pelunasan
        $this->get(route('receipts.create', ['document_id' => $invoice->id]))->assertOk()->assertSee($invoice->number);
        $this->post(route('payments.store'), [
            'type' => 'receipt', 'party_id' => $so->customer_id, 'payment_date' => '2026-10-10', 'method' => 'transfer',
            'allocations' => [$invoice->id => 1000000],
        ])->assertRedirect();
        $this->assertSame(InvoiceStatus::Partial, $invoice->fresh()->status);
        $this->assertSame('RV-202610-0001', Payment::first()->number);

        // Lebih bayar ditolak
        $this->post(route('payments.store'), [
            'type' => 'receipt', 'party_id' => $so->customer_id, 'payment_date' => '2026-10-11', 'method' => 'cash',
            'allocations' => [$invoice->id => 1131201],
        ])->assertSessionHasErrors("allocations.{$invoice->id}");

        $this->post(route('payments.store'), [
            'type' => 'receipt', 'party_id' => $so->customer_id, 'payment_date' => '2026-10-12', 'method' => 'cash',
            'allocations' => [$invoice->id => 1131200],
        ]);
        $invoice->refresh();
        $this->assertSame(InvoiceStatus::Paid, $invoice->status);
        $this->assertEquals(0, $invoice->outstanding());

        // Invoice lunas tidak dapat dibatalkan
        $this->post(route('invoices.cancel', $invoice))->assertSessionHasErrors('status');

        // Batalkan pembayaran kedua -> kembali dibayar sebagian
        $second = Payment::latest('id')->first();
        $this->post(route('payments.cancel', $second), ['reason' => 'Salah input'])->assertRedirect();
        $this->assertSame(PaymentStatus::Cancelled, $second->fresh()->status);
        $this->assertSame(InvoiceStatus::Partial, $invoice->fresh()->status);
        $this->assertEquals(1000000, (float) $invoice->fresh()->paid_amount);

        // Halaman & PDF
        $this->get(route('invoices.index'))->assertOk()->assertSee($invoice->number);
        $this->get(route('invoices.show', $invoice))->assertOk()->assertSee($second->number);
        $this->get(route('sales-orders.show', $so))->assertOk()->assertSee($invoice->number);
        $this->get(route('receipts.index'))->assertOk()->assertSee($second->number);
        $this->get(route('payments.show', $second))->assertOk()->assertSee('Salah input');

        foreach ([route('invoices.pdf', $invoice), route('payments.pdf', $second)] as $url) {
            $response = $this->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }
    }

    public function test_last_invoice_takes_remaining_discount_and_cancel_releases_quantity(): void
    {
        $so = $this->deliveredSalesOrder();
        $item = $so->items()->first();

        $this->post(route('invoices.store'), ['sales_order_id' => $so->id, 'invoice_date' => '2026-10-02', 'items' => [$item->id => 10]]);
        $first = Invoice::first();
        $this->assertEquals(10000, (float) $first->discount);

        // Batalkan -> qty bisa ditagih ulang
        $this->post(route('invoices.cancel', $first))->assertRedirect();
        $this->assertSame(InvoiceStatus::Cancelled, $first->fresh()->status);
        $this->assertEquals(0, (float) $item->fresh()->invoiced_quantity);

        $this->post(route('invoices.store'), ['sales_order_id' => $so->id, 'invoice_date' => '2026-10-02', 'items' => [$item->id => 30]]);
        $this->post(route('sales-orders.deliver.store', $so), ['delivery_date' => '2026-10-05', 'items' => [$item->id => 20]]);
        $this->post(route('invoices.store'), ['sales_order_id' => $so->id, 'invoice_date' => '2026-10-05', 'items' => [$item->id => 20]]);

        $active = Invoice::where('status', '!=', 'cancelled')->get();
        $this->assertCount(2, $active);
        $this->assertEquals(50000, (float) $active->sum('discount'));
        $this->assertEquals((float) $so->total, (float) $active->sum('total'));
    }

    public function test_bill_matches_receipt_and_one_payment_settles_many_bills(): void
    {
        $po = $this->receivedPurchaseOrder();
        $item = $po->items()->first();

        $this->get(route('bills.create', ['purchase_order_id' => $po->id]))->assertOk();

        // Melebihi barang diterima (60) ditolak
        $this->post(route('bills.store'), [
            'purchase_order_id' => $po->id, 'bill_date' => '2026-10-04',
            'items' => [$item->id => ['quantity' => 61, 'price' => 120000]],
        ])->assertSessionHasErrors("items.{$item->id}.quantity");

        $this->post(route('bills.store'), [
            'purchase_order_id' => $po->id, 'bill_date' => '2026-10-04', 'supplier_invoice_number' => 'FK-001',
            'items' => [$item->id => ['quantity' => 40, 'price' => 125000]],
        ])->assertRedirect();
        $this->post(route('bills.store'), [
            'purchase_order_id' => $po->id, 'bill_date' => '2026-10-05', 'supplier_invoice_number' => 'FK-001',
            'items' => [$item->id => ['quantity' => 20]],
        ])->assertSessionHasErrors('supplier_invoice_number');
        $this->post(route('bills.store'), [
            'purchase_order_id' => $po->id, 'bill_date' => '2026-10-05', 'supplier_invoice_number' => 'FK-002', 'discount' => 100000,
            'items' => [$item->id => ['quantity' => 20]],
        ])->assertRedirect();

        [$b1, $b2] = Bill::orderBy('id')->get();
        $this->assertEquals(5000000, (float) $b1->total); // harga faktur berbeda dari PO
        $this->assertEquals(2300000, (float) $b2->total); // 20 x 120.000 - 100.000
        $this->assertEquals(60, (float) $item->fresh()->billed_quantity);
        $this->assertSame('2026-11-03', $b1->due_date->toDateString()); // termin default 30 hari

        $this->post(route('payments.store'), [
            'type' => 'disbursement', 'party_id' => $po->supplier_id, 'payment_date' => '2026-10-20', 'method' => 'giro', 'reference' => 'BG-778',
            'allocations' => [$b1->id => 5000000, $b2->id => 300000],
        ])->assertRedirect();

        $payment = Payment::first();
        $this->assertSame('PV-202610-0001', $payment->number);
        $this->assertEquals(5300000, (float) $payment->amount);
        $this->assertSame(InvoiceStatus::Paid, $b1->fresh()->status);
        $this->assertSame(InvoiceStatus::Partial, $b2->fresh()->status);

        // Tagihan milik supplier lain tidak bisa dialokasikan
        $other = Supplier::where('id', '!=', $po->supplier_id)->first();
        $this->post(route('payments.store'), [
            'type' => 'disbursement', 'party_id' => $other->id, 'payment_date' => '2026-10-20', 'method' => 'cash',
            'allocations' => [$b2->id => 1000],
        ])->assertSessionHasErrors("allocations.{$b2->id}");

        $this->get(route('bills.index'))->assertOk()->assertSee('FK-002');
        $this->get(route('bills.show', $b2))->assertOk()->assertSee($payment->number);
        $this->get(route('purchase-orders.show', $po))->assertOk()->assertSee($b1->number);
        $this->get(route('disbursements.index'))->assertOk()->assertSee('BG-778');
        $this->get(route('disbursements.create', ['party_id' => $po->supplier_id]))->assertOk()->assertSee($b2->number);
        $this->get(route('payments.pdf', $payment))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_aging_report_buckets_balances_as_of_date(): void
    {
        $so = $this->deliveredSalesOrder();
        $item = $so->items()->first();

        // Invoice 10 pcs, jatuh tempo 16 Okt; invoice 20 pcs, jatuh tempo 20 Nov
        $this->post(route('invoices.store'), ['sales_order_id' => $so->id, 'invoice_date' => '2026-10-02', 'items' => [$item->id => 10]]);
        $this->post(route('invoices.store'), ['sales_order_id' => $so->id, 'invoice_date' => '2026-10-02', 'due_date' => '2026-11-20', 'items' => [$item->id => 20]]);
        [$a, $b] = Invoice::orderBy('id')->get();

        $this->post(route('payments.store'), [
            'type' => 'receipt', 'party_id' => $so->customer_id, 'payment_date' => '2026-11-01', 'method' => 'transfer',
            'allocations' => [$a->id => 100000],
        ]);

        $report = app(AgingReport::class);

        // Per 31 Okt: invoice A lewat 15 hari, pembayaran 1 Nov belum dihitung
        $oct = $report->build('receivables', Carbon::parse('2026-10-31'));
        $this->assertEquals((float) $a->total, $oct['totals']['1_30']);
        $this->assertEquals((float) $b->total, $oct['totals']['current']);

        // Per 20 Des: A lewat 65 hari (sisa setelah bayar), B lewat 30 hari
        $dec = $report->build('receivables', Carbon::parse('2026-12-20'));
        $this->assertEquals((float) $a->total - 100000, $dec['totals']['61_90']);
        $this->assertEquals((float) $b->total, $dec['totals']['1_30']);
        $this->assertEquals((float) $a->total + (float) $b->total - 100000, $dec['totals']['total']);

        $this->get(route('reports.receivables-aging', ['as_of' => '2026-12-20']))->assertOk()->assertSee($so->customer->name);
        $this->get(route('reports.payables-aging'))->assertOk();
    }

    public function test_finance_permissions(): void
    {
        $so = $this->deliveredSalesOrder();
        $this->post(route('invoices.store'), ['sales_order_id' => $so->id, 'invoice_date' => '2026-10-02', 'items' => [$so->items()->first()->id => 20]]);
        $invoice = Invoice::first();

        // Keuangan: kelola piutang & hutang
        $this->actingAs(User::factory()->role(Role::Keuangan)->create());
        foreach (['invoices.index', 'invoices.create', 'bills.index', 'bills.create', 'receipts.create', 'disbursements.create', 'reports.receivables-aging', 'reports.payables-aging'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('sales-orders.show', $so))->assertOk()->assertSee('Buat Invoice', false);
        $this->get(route('stock.adjustment'))->assertForbidden();

        // Sales: hanya melihat piutang
        $this->actingAs(User::factory()->role(Role::Sales)->create());
        $this->get(route('invoices.index'))->assertOk();
        $this->get(route('invoices.pdf', $invoice))->assertOk();
        $this->get(route('reports.receivables-aging'))->assertOk();
        $this->get(route('invoices.create'))->assertForbidden();
        $this->get(route('receipts.create'))->assertForbidden();
        $this->post(route('payments.store'), [
            'type' => 'receipt', 'party_id' => $so->customer_id, 'payment_date' => '2026-10-10', 'method' => 'cash',
            'allocations' => [$invoice->id => 1000],
        ])->assertForbidden();
        $this->get(route('bills.index'))->assertForbidden();
        $this->get(route('disbursements.index'))->assertForbidden();

        // Purchasing: hanya melihat hutang
        $this->actingAs(User::factory()->role(Role::Purchasing)->create());
        $this->get(route('bills.index'))->assertOk();
        $this->get(route('bills.create'))->assertForbidden();
        $this->get(route('invoices.index'))->assertForbidden();

        // Gudang: tidak ada akses keuangan
        $this->actingAs(User::factory()->role(Role::Gudang)->create());
        $this->get(route('invoices.index'))->assertForbidden();
        $this->get(route('bills.index'))->assertForbidden();
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Piutang jatuh tempo');
    }
}
