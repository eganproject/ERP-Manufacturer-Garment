<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Material;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Reports\TradeReport;
use App\Services\StockService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GrossProfitAndApprovalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-25 10:00:00');
        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * Stok 100 pcs @40.000 lalu 100 pcs @50.000 → rata-rata 45.000.
     * SO 50 pcs @65.000 diskon 50.000, kirim 30 pcs, tagih 30 pcs.
     */
    private function soldWithCost(): SalesOrder
    {
        $variant = Product::where('code', 'TS-BSC')->with('variants')->first()->variants->first();
        DB::transaction(function () use ($variant) {
            app(StockService::class)->move($variant, 100, StockMovementType::Adjustment, unitCost: 40000);
            app(StockService::class)->move($variant, 100, StockMovementType::Adjustment, unitCost: 50000);
        });

        $this->post(route('sales-orders.store'), [
            'customer_id' => Customer::first()->id,
            'order_date' => '2026-10-01',
            'discount' => 50000,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 50, 'price' => 65000]],
        ]);
        $so = SalesOrder::first();
        $this->post(route('sales-orders.confirm', $so));
        $this->post(route('sales-orders.deliver.store', $so), ['delivery_date' => '2026-10-02', 'items' => [$so->items()->first()->id => 30]])
            ->assertSessionHasNoErrors();
        $this->post(route('invoices.store'), [
            'sales_order_id' => $so->id, 'invoice_date' => '2026-10-02', 'tax_rate' => 11,
            'items' => [$so->items()->first()->id => 30],
        ])->assertSessionHasNoErrors();

        return $so;
    }

    public function test_delivery_and_invoice_record_cost_of_goods_sold(): void
    {
        $this->soldWithCost();

        $deliveryItem = Delivery::first()->items->first();
        $this->assertEquals(45000, (float) $deliveryItem->unit_cost);
        $this->assertEquals(1350000, (float) $deliveryItem->cost);

        $invoice = Invoice::with('items')->first();
        $this->assertEquals(45000, (float) $invoice->items->first()->unit_cost);
        $this->assertEquals(1350000, $invoice->costOfGoods());
        // DPP = 30 × 65.000 − diskon proporsional 30.000 = 1.920.000
        $this->assertEquals(1920000, $invoice->netSales());
        $this->assertEquals(570000, $invoice->grossProfit());
    }

    public function test_sales_report_shows_gross_profit_per_customer_and_product(): void
    {
        $this->soldWithCost();
        $report = app(TradeReport::class);

        $byParty = $report->build('sales', Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), 'party', null, true);
        $this->assertEquals(1350000, $byParty['totals']['cost']);
        $this->assertEquals(570000, $byParty['totals']['gross_profit']);
        $this->assertEquals(29.7, $byParty['totals']['margin']);
        $this->assertEquals(570000, $byParty['rows']->first()['gross_profit']);

        $byItem = $report->build('sales', Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), 'item', null, true);
        $this->assertEquals(570000, $byItem['rows']->first()['gross_profit']);

        $this->get(route('reports.sales'))->assertOk()->assertSee('Laba Kotor')->assertSee('570.000');
        $this->get(route('reports.sales', ['group' => 'item']))->assertOk()->assertSee('Laba Kotor');
        $this->get(route('reports.sales', ['export' => 'xlsx']))->assertOk();
        $this->get(route('reports.sales', ['export' => 'pdf']))->assertOk();
        $this->get(route('invoices.show', Invoice::first()))->assertOk()->assertSee('HPP / Laba Kotor');
        $this->get(route('dashboard'))->assertOk()->assertSee('Laba kotor bulan ini')->assertSee('Produk paling menguntungkan');
    }

    public function test_cost_columns_hidden_without_costing_permission(): void
    {
        $this->soldWithCost();

        $this->actingAs(User::factory()->role(Role::Sales)->create());
        $this->get(route('reports.sales'))->assertOk()->assertDontSee('Laba Kotor');
        $this->get(route('dashboard'))->assertOk()->assertDontSee('Laba kotor bulan ini');
    }

    private function draftPo(User $creator, float $price): PurchaseOrder
    {
        $this->actingAs($creator)->post(route('purchase-orders.store'), [
            'supplier_id' => Supplier::first()->id,
            'order_date' => '2026-10-20',
            'items' => [['material_id' => Material::first()->id, 'quantity' => 100, 'price' => $price]],
        ])->assertSessionHasNoErrors();

        return PurchaseOrder::latest('id')->first();
    }

    public function test_po_below_threshold_is_ordered_directly(): void
    {
        Setting::put(['po_approval_threshold' => 10000000]);
        $purchasing = User::factory()->role(Role::Purchasing)->create();

        $po = $this->draftPo($purchasing, 50000); // 5 juta
        $this->post(route('purchase-orders.submit', $po));

        $this->assertSame(PurchaseOrderStatus::Ordered, $po->fresh()->status);
    }

    public function test_po_above_threshold_needs_approval_by_another_user(): void
    {
        Setting::put(['po_approval_threshold' => 10000000]);
        $purchasing = User::factory()->role(Role::Purchasing)->create();
        $finance = User::factory()->role(Role::Keuangan)->create();

        $po = $this->draftPo($purchasing, 150000); // 15 juta
        $this->get(route('purchase-orders.show', $po))->assertSee('Ajukan Persetujuan');
        $this->post(route('purchase-orders.submit', $po));
        $po->refresh();
        $this->assertSame(PurchaseOrderStatus::PendingApproval, $po->status);
        $this->assertFalse($po->canReceive());

        // Purchasing tidak boleh menyetujui; gudang belum bisa menerima barang
        $this->post(route('purchase-orders.approve', $po))->assertForbidden();
        $this->actingAs(User::factory()->role(Role::Gudang)->create())
            ->get(route('purchase-orders.receive', $po))->assertForbidden();

        $this->actingAs($finance)->get(route('purchase-orders.show', $po))->assertOk()->assertSee('Setujui');
        $this->get(route('dashboard'))->assertSee('PO menunggu persetujuan');
        $this->post(route('purchase-orders.approve', $po), ['approval_notes' => 'Sesuai anggaran'])->assertSessionHasNoErrors();

        $po->refresh();
        $this->assertSame(PurchaseOrderStatus::Ordered, $po->status);
        $this->assertSame($finance->id, $po->approved_by);
        $this->assertNotNull($po->approved_at);
        $this->assertTrue($po->canReceive());
    }

    public function test_rejected_po_returns_to_draft_with_reason(): void
    {
        Setting::put(['po_approval_threshold' => 10000000]);
        $purchasing = User::factory()->role(Role::Purchasing)->create();
        $po = $this->draftPo($purchasing, 150000);
        $this->post(route('purchase-orders.submit', $po));

        $finance = User::factory()->role(Role::Keuangan)->create();
        $this->actingAs($finance)->post(route('purchase-orders.reject', $po))->assertSessionHasErrors('approval_notes');
        $this->post(route('purchase-orders.reject', $po), ['approval_notes' => 'Harga terlalu tinggi'])->assertSessionHasNoErrors();

        $po->refresh();
        $this->assertSame(PurchaseOrderStatus::Draft, $po->status);
        $this->assertSame('Harga terlalu tinggi', $po->approval_notes);
        $this->actingAs($purchasing)->get(route('purchase-orders.show', $po))->assertSee('Harga terlalu tinggi');
    }

    public function test_creator_cannot_approve_own_po_unless_admin(): void
    {
        Setting::put(['po_approval_threshold' => 1000000]);
        $finance = User::factory()->role(Role::Keuangan)->create();
        // Keuangan tidak bisa membuat PO, jadi PO dibuat admin lalu pembuatnya diganti ke keuangan
        $po = $this->draftPo(User::factory()->create(), 150000);
        $this->post(route('purchase-orders.submit', $po));
        $po->update(['created_by' => $finance->id]);

        $this->actingAs($finance)->post(route('purchase-orders.approve', $po))->assertSessionHasErrors('status');
        $this->assertSame(PurchaseOrderStatus::PendingApproval, $po->fresh()->status);

        $admin = User::factory()->create();
        $po->update(['created_by' => $admin->id]);
        $this->actingAs($admin)->post(route('purchase-orders.approve', $po))->assertSessionHasNoErrors();
        $this->assertSame(PurchaseOrderStatus::Ordered, $po->fresh()->status);
    }

    public function test_threshold_zero_disables_approval_and_setting_is_saved(): void
    {
        $this->put(route('settings.update'), ['company_name' => 'CV Uji', 'po_approval_threshold' => 2500000])->assertSessionHasNoErrors();
        $this->assertEquals(2500000, Setting::poApprovalThreshold());

        Setting::put(['po_approval_threshold' => 0]);
        $po = $this->draftPo(User::factory()->create(), 500000); // 50 juta
        $this->post(route('purchase-orders.submit', $po));
        $this->assertSame(PurchaseOrderStatus::Ordered, $po->fresh()->status);
    }
}
