<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\ReturnStatus;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Models\Bill;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AgingReport;
use App\Services\Reports\TradeReport;
use App\Services\StockService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReturnsTest extends TestCase
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
            app(StockService::class)->move($this->variant, 100, StockMovementType::Adjustment, unitCost: 40000);
            app(StockService::class)->move($this->variant, 100, StockMovementType::Adjustment, unitCost: 50000);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * SO 50 pcs @65.000 diskon 50.000 (HPP rata-rata 45.000), kirim 30 pcs, tagih $invoiceQty pcs PPN 11%.
     */
    private function soldOrder(float $invoiceQty = 30): SalesOrder
    {
        $this->post(route('sales-orders.store'), [
            'customer_id' => Customer::first()->id,
            'order_date' => '2026-10-01',
            'discount' => 50000,
            'items' => [['product_variant_id' => $this->variant->id, 'quantity' => 50, 'price' => 65000]],
        ]);
        $so = SalesOrder::first();
        $this->post(route('sales-orders.confirm', $so));
        $this->post(route('sales-orders.deliver.store', $so), ['delivery_date' => '2026-10-02', 'items' => [$so->items()->first()->id => 30]])
            ->assertSessionHasNoErrors();
        $this->post(route('invoices.store'), [
            'sales_order_id' => $so->id, 'invoice_date' => '2026-10-02', 'tax_rate' => 11,
            'items' => [$so->items()->first()->id => $invoiceQty],
        ])->assertSessionHasNoErrors();

        return $so->fresh();
    }

    private function returnSales(SalesOrder $so, float $qty, string $date = '2026-10-10')
    {
        return $this->post(route('sales-returns.store'), [
            'order_id' => $so->id, 'return_date' => $date, 'reason' => 'Jahitan lepas',
            'items' => [$so->items()->first()->id => $qty],
        ]);
    }

    public function test_sales_return_of_invoiced_goods_restocks_and_credits_invoice(): void
    {
        $so = $this->soldOrder();
        $invoice = Invoice::first();
        $this->assertEquals(2131200, (float) $invoice->total);

        $this->returnSales($so, 5)->assertRedirect()->assertSessionHasNoErrors();

        $return = SalesReturn::with('items')->first();
        $this->assertSame('RJ-202610-0001', $return->number);
        // DPP 5 × 65.000 setelah diskon proporsional = 320.000, PPN 11% = 35.200
        $this->assertEquals(320000, (float) $return->amount);
        $this->assertEquals(355200, (float) $return->total);
        $this->assertEquals(355200, (float) $return->applied_amount);
        $this->assertEquals(0, $return->balance());
        $this->assertEquals(225000, $return->billedCost());

        // Stok kembali dengan HPP rata-rata saat kirim
        $this->variant->refresh();
        $this->assertEquals(175, (float) $this->variant->stock);
        $this->assertEquals(45000, (float) $this->variant->average_cost);
        $this->assertDatabaseHas('stock_movements', ['type' => 'sales_return', 'quantity' => 5, 'reference_number' => $return->number]);

        $invoice->refresh();
        $this->assertEquals(355200, (float) $invoice->credited_amount);
        $this->assertEquals(1776000, $invoice->outstanding());
        $this->assertSame(InvoiceStatus::Partial, $invoice->status);
        $this->assertFalse($invoice->canCancel());

        $item = $so->items()->first();
        $this->assertEquals(5, (float) $item->returned_quantity);
        $this->assertEquals(5, (float) $item->returned_invoiced_quantity);
        $this->assertEquals(0, $item->uninvoicedQuantity());

        // Laba kotor dikoreksi: DPP 1.920.000 − 320.000, HPP 1.350.000 − 225.000
        $report = app(TradeReport::class)->build('sales', Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), 'party', null, true);
        $this->assertEquals(1600000, $report['totals']['dpp']);
        $this->assertEquals(320000, $report['totals']['returns']);
        $this->assertEquals(1125000, $report['totals']['cost']);
        $this->assertEquals(475000, $report['totals']['gross_profit']);
        $this->assertEquals(1, $report['totals']['count']);
        $byItem = app(TradeReport::class)->build('sales', Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), 'item', null, true);
        $this->assertEquals(25, $byItem['rows']->first()['quantity']);
        $this->assertEquals(475000, $byItem['rows']->first()['gross_profit']);

        $this->get(route('reports.sales'))->assertOk()->assertSee('setelah retur');
        $this->get(route('sales-returns.index'))->assertOk()->assertSee($return->number);
        $this->get(route('sales-returns.show', $return))->assertOk()->assertSee('Nota Kredit')->assertSee($invoice->number);
        $this->get(route('sales-returns.pdf', $return))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('invoices.show', $invoice))->assertOk()->assertSee('Nota kredit retur')->assertSee($return->number);
        $this->get(route('invoices.pdf', $invoice))->assertOk();
        $this->get(route('sales-orders.show', $so))->assertOk()->assertSee('Retur Barang');
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_return_splits_uninvoiced_and_invoiced_parts(): void
    {
        $so = $this->soldOrder(20);
        $item = $so->items()->first();

        $this->returnSales($so, 31)->assertSessionHasErrors("items.{$item->id}");

        // 10 pcs belum ditagih, retur 12 → 2 pcs menjadi nota kredit
        $this->returnSales($so, 12)->assertSessionHasNoErrors();
        $return = SalesReturn::first();
        $this->assertEquals(2, (float) $return->items->first()->billed_quantity);
        $this->assertEquals(128000, (float) $return->amount);
        $this->assertEquals(142080, (float) $return->total);

        $item->refresh();
        $this->assertEquals(0, $item->uninvoicedQuantity());
        $this->assertEquals(18, $item->returnableQuantity());

        $this->post(route('invoices.store'), [
            'sales_order_id' => $so->id, 'invoice_date' => '2026-10-11', 'items' => [$item->id => 1],
        ])->assertSessionHasErrors("items.{$item->id}");
    }

    public function test_credit_balance_can_be_applied_to_other_invoice_and_refunded(): void
    {
        $so = $this->soldOrder();
        $first = Invoice::first();
        $this->post(route('payments.store'), [
            'type' => 'receipt', 'party_id' => $so->customer_id, 'payment_date' => '2026-10-05', 'method' => 'transfer',
            'allocations' => [$first->id => 2131200],
        ])->assertSessionHasNoErrors();

        $this->returnSales($so, 5)->assertSessionHasNoErrors();
        $return = SalesReturn::first();
        $this->assertEquals(355200, $return->balance());
        $this->assertSame('Sisa Nota', $return->displayStatus()['label']);

        // Kirim & tagih lagi 10 pcs, lalu pakai sebagian nota
        $this->post(route('sales-orders.deliver.store', $so), ['delivery_date' => '2026-10-12', 'items' => [$so->items()->first()->id => 10]]);
        $this->post(route('invoices.store'), [
            'sales_order_id' => $so->id, 'invoice_date' => '2026-10-12', 'items' => [$so->items()->first()->id => 10],
        ])->assertSessionHasNoErrors();
        $second = Invoice::latest('id')->first();

        $this->get(route('sales-returns.show', $return))->assertOk()->assertSee('Pakai Sisa Nota');
        $this->post(route('sales-returns.apply', $return), ['document_id' => $second->id, 'amount' => 400000])->assertSessionHasErrors('amount');
        $this->post(route('sales-returns.apply', $return), ['document_id' => $second->id, 'amount' => 100000])->assertSessionHasNoErrors();
        $this->assertEquals(100000, (float) $second->fresh()->credited_amount);

        $this->post(route('sales-returns.refund', $return), [
            'refund_date' => '2026-10-15', 'refund_method' => 'transfer', 'refund_reference' => 'TRF-01', 'amount' => 255200,
        ])->assertSessionHasNoErrors();

        $return->refresh();
        $this->assertEquals(0, $return->balance());
        $this->assertEquals(255200, (float) $return->refunded_amount);
        $this->assertFalse($return->canCancel());
        $this->post(route('sales-returns.cancel', $return), ['reason' => 'Salah input'])->assertSessionHasErrors('status');
    }

    public function test_cancel_sales_return_restores_stock_and_receivable(): void
    {
        $so = $this->soldOrder();
        $this->returnSales($so, 5);
        $return = SalesReturn::first();

        $this->post(route('sales-returns.cancel', $return), ['reason' => 'Salah input'])->assertSessionHasNoErrors();

        $return->refresh();
        $this->assertSame(ReturnStatus::Cancelled, $return->status);
        $this->assertEquals(170, (float) $this->variant->fresh()->stock);
        $this->assertEquals(0, (float) $so->items()->first()->returned_quantity);

        $invoice = Invoice::first();
        $this->assertEquals(0, (float) $invoice->credited_amount);
        $this->assertEquals(2131200, $invoice->outstanding());
        $this->assertSame(InvoiceStatus::Unpaid, $invoice->status);
        $this->assertTrue($invoice->canCancel());
    }

    private function billedPurchaseOrder(): PurchaseOrder
    {
        $this->post(route('purchase-orders.store'), [
            'supplier_id' => Supplier::first()->id,
            'order_date' => '2026-10-01',
            'items' => [['material_id' => Material::where('code', 'KN-CMB30-HTM')->first()->id, 'quantity' => 100, 'price' => 120000]],
        ]);
        $po = PurchaseOrder::first();
        $this->post(route('purchase-orders.submit', $po));
        $this->post(route('purchase-orders.receive.store', $po), ['receipt_date' => '2026-10-03', 'items' => [$po->items()->first()->id => 60]]);
        $this->post(route('bills.store'), [
            'purchase_order_id' => $po->id, 'bill_date' => '2026-10-04', 'tax_rate' => 11,
            'items' => [$po->items()->first()->id => ['quantity' => 40, 'price' => 125000]],
        ])->assertSessionHasNoErrors();

        return $po->fresh();
    }

    public function test_purchase_return_takes_stock_out_and_debits_bill(): void
    {
        $po = $this->billedPurchaseOrder();
        $item = $po->items()->first();
        $material = $item->material;
        $stockBefore = (float) $material->stock;

        // 20 diterima belum ditagih, retur 25 → 5 menjadi nota debit @125.000 (harga tagihan)
        $this->post(route('purchase-returns.store'), [
            'order_id' => $po->id, 'return_date' => '2026-10-06', 'reason' => 'Kain cacat',
            'items' => [$item->id => 25],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $return = PurchaseReturn::first();
        $this->assertSame('RB-202610-0001', $return->number);
        $this->assertEquals(625000, (float) $return->amount);
        $this->assertEquals(693750, (float) $return->total);
        $this->assertEquals(693750, (float) $return->applied_amount);
        $this->assertEquals($stockBefore - 25, (float) $material->fresh()->stock);

        $bill = Bill::first();
        $this->assertEquals(5550000 - 693750, $bill->fresh()->outstanding());
        $this->assertEquals(0, $item->fresh()->unbilledQuantity());

        $this->post(route('bills.store'), [
            'purchase_order_id' => $po->id, 'bill_date' => '2026-10-07', 'items' => [$item->id => ['quantity' => 1]],
        ])->assertSessionHasErrors("items.{$item->id}.quantity");

        $aging = app(AgingReport::class)->build('payables', Carbon::parse('2026-10-25'));
        $this->assertEquals(4856250, $aging['totals']['total']);
        $agingBefore = app(AgingReport::class)->build('payables', Carbon::parse('2026-10-05'));
        $this->assertEquals(5550000, $agingBefore['totals']['total']);

        $report = app(TradeReport::class)->build('purchases', Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
        $this->assertEquals(4375000, $report['totals']['dpp']);

        $this->get(route('purchase-returns.show', $return))->assertOk()->assertSee('Nota Debit');
        $this->get(route('purchase-returns.pdf', $return))->assertOk();
        $this->get(route('purchase-returns.index'))->assertOk()->assertSee($return->number);
        $this->get(route('purchase-returns.create', ['order_id' => $po->id]))->assertOk();
        $this->get(route('bills.show', $bill))->assertOk()->assertSee('Nota debit retur');
        $this->get(route('purchase-orders.show', $po))->assertOk()->assertSee('Retur ke Supplier');

        // Batal: bahan masuk lagi, hutang kembali
        $this->post(route('purchase-returns.cancel', $return), ['reason' => 'Supplier menolak retur'])->assertSessionHasNoErrors();
        $this->assertEquals($stockBefore, (float) $material->fresh()->stock);
        $this->assertEquals(5550000, $bill->fresh()->outstanding());
    }

    public function test_purchase_return_cannot_exceed_stock(): void
    {
        $po = $this->billedPurchaseOrder();
        $item = $po->items()->first();
        $item->material->forceFill(['stock' => 10])->save();

        $this->post(route('purchase-returns.store'), [
            'order_id' => $po->id, 'return_date' => '2026-10-06', 'reason' => 'Kain cacat', 'items' => [$item->id => 20],
        ])->assertSessionHasErrors('stock');
        $this->assertSame(0, PurchaseReturn::count());
        $this->assertEquals(0, (float) $item->fresh()->returned_quantity);
    }

    public function test_return_permissions_follow_roles(): void
    {
        $so = $this->soldOrder();
        $this->returnSales($so, 5);
        $return = SalesReturn::first();

        $this->actingAs(User::factory()->role(Role::Produksi)->create());
        $this->get(route('sales-returns.create'))->assertForbidden();
        $this->get(route('purchase-returns.index'))->assertForbidden();

        $this->actingAs(User::factory()->role(Role::Sales)->create());
        $this->get(route('sales-returns.create', ['order_id' => $so->id]))->assertOk();
        $this->post(route('sales-returns.refund', $return), ['refund_date' => '2026-10-15', 'refund_method' => 'cash', 'amount' => 1])->assertForbidden();

        $this->actingAs(User::factory()->role(Role::Gudang)->create());
        $this->get(route('purchase-returns.create'))->assertOk();
        $this->get(route('sales-returns.show', $return))->assertOk()->assertDontSee('Koreksi HPP');
    }
}
