<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StageStatus;
use App\Enums\StockMovementType;
use App\Models\Customer;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Reports\ProductionReport;
use App\Services\Reports\StockValuationReport;
use App\Services\Reports\TradeReport;
use App\Services\StockService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use ZipArchive;

class ReportsTest extends TestCase
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

    /** SO 50 pcs @65.000 diskon 50.000, 30 pcs dikirim lalu ditagih dengan PPN 11%. */
    private function invoicedSale(): SalesOrder
    {
        $variant = Product::where('code', 'TS-BSC')->with('variants')->first()->variants->first();
        DB::transaction(fn () => app(StockService::class)->move($variant, 100, StockMovementType::Adjustment));

        $this->post(route('sales-orders.store'), [
            'customer_id' => Customer::first()->id,
            'order_date' => '2026-10-01',
            'discount' => 50000,
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 50, 'price' => 65000]],
        ]);
        $so = SalesOrder::first();
        $this->post(route('sales-orders.confirm', $so));
        $this->post(route('sales-orders.deliver.store', $so), ['delivery_date' => '2026-10-02', 'items' => [$so->items()->first()->id => 30]]);
        $this->post(route('invoices.store'), [
            'sales_order_id' => $so->id, 'invoice_date' => '2026-10-02', 'tax_rate' => 11,
            'items' => [$so->items()->first()->id => 30],
        ])->assertSessionHasNoErrors();

        return $so;
    }

    private function billedPurchase(): PurchaseOrder
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
        $this->post(route('bills.store'), [
            'purchase_order_id' => $po->id, 'bill_date' => '2026-10-04',
            'items' => [$po->items()->first()->id => ['quantity' => 60, 'price' => 120000]],
        ])->assertSessionHasNoErrors();

        return $po;
    }

    private function completedProduction(): ProductionOrder
    {
        $product = Product::where('code', 'TS-BSC')->with('variants')->first();

        $this->post(route('production-orders.store'), [
            'product_id' => $product->id,
            'start_date' => '2026-10-20',
            'due_date' => '2026-10-30',
            'items' => [$product->variants[0]->id => 100],
        ]);
        $spk = ProductionOrder::first();
        $this->post(route('production-orders.start', $spk))->assertSessionHasNoErrors();

        foreach ($spk->stages()->get() as $stage) {
            $reject = $stage->stage->value === 'sewing' ? 4 : 0;
            $this->put(route('production-orders.stages.update', [$spk, $stage]), [
                'status' => StageStatus::Done->value, 'output_quantity' => 100 - $reject, 'reject_quantity' => $reject,
            ]);
        }

        $this->post(route('production-orders.complete', $spk), [
            'finished' => [$spk->items()->first()->id => 96], 'reject_quantity' => 4,
        ])->assertSessionHasNoErrors();

        return $spk->fresh();
    }

    public function test_sales_report_groups_invoices_by_customer_product_and_month(): void
    {
        $so = $this->invoicedSale();
        $period = [Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31')];
        $report = app(TradeReport::class);

        // DPP = 1.950.000 - 30.000 = 1.920.000; PPN 211.200
        $byCustomer = $report->build('sales', ...$period);
        $this->assertEquals(1920000, $byCustomer['totals']['dpp']);
        $this->assertEquals(211200, $byCustomer['totals']['tax']);
        $this->assertEquals(2131200, $byCustomer['totals']['outstanding']);
        $this->assertEquals(30, $byCustomer['totals']['quantity']);
        $this->assertSame($so->customer->name, $byCustomer['rows'][0]['name']);
        $this->assertEquals(100, $byCustomer['rows'][0]['share']);
        // Pembanding SO dikonfirmasi: 3.250.000 - 50.000
        $this->assertEquals(3200000, $byCustomer['summary']['orders']);

        // Per produk: diskon header dibagi proporsional
        $byItem = $report->build('sales', ...$period, groupBy: 'item');
        $this->assertSame('TS-BSC', $byItem['rows'][0]['code']);
        $this->assertEquals(1920000, $byItem['rows'][0]['dpp']);
        $this->assertEquals(64000, $byItem['rows'][0]['average_price']);

        $byMonth = $report->build('sales', ...$period, groupBy: 'month');
        $this->assertSame('2026-10', $byMonth['rows'][0]['key']);

        // Di luar periode -> kosong
        $this->assertTrue($report->build('sales', Carbon::parse('2026-09-01'), Carbon::parse('2026-09-30'))['rows']->isEmpty());

        foreach (['party', 'item', 'month'] as $group) {
            $this->get(route('reports.sales', ['from' => '2026-10-01', 'to' => '2026-10-31', 'group' => $group]))
                ->assertOk()
                ->assertSee('1.920.000');
        }
    }

    public function test_purchase_report_uses_supplier_bills(): void
    {
        $po = $this->billedPurchase();
        $data = app(TradeReport::class)->build('purchases', Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), 'item');

        $this->assertEquals(7200000, $data['totals']['dpp']);
        $this->assertSame('KN-CMB30-HTM', $data['rows'][0]['code']);
        $this->assertEquals(60, $data['rows'][0]['quantity']);

        $this->get(route('reports.purchases', ['from' => '2026-10-01', 'to' => '2026-10-31']))
            ->assertOk()
            ->assertSee($po->supplier->name)
            ->assertSee('7.200.000');
    }

    public function test_production_report_summarises_output_reject_and_on_time(): void
    {
        $spk = $this->completedProduction();
        $data = app(ProductionReport::class)->build(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));

        $this->assertSame(1, $data['totals']['count']);
        $this->assertEquals(96, $data['totals']['finished']);
        $this->assertEquals(4, $data['totals']['reject']);
        $this->assertEquals(4.0, $data['totals']['reject_rate']);
        $this->assertEquals(96.0, $data['totals']['achievement']);
        $this->assertEquals(100.0, $data['totals']['on_time']);
        $this->assertEquals(5, $data['totals']['lead_days']);

        $sewing = $data['stages']->firstWhere('stage.value', 'sewing');
        $this->assertEquals(4, $sewing['reject']);

        $this->get(route('reports.production', ['from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk()->assertSee('TS-BSC');
        $this->get(route('reports.production', ['from' => '2026-10-01', 'to' => '2026-10-31', 'detail' => 'orders']))->assertOk()->assertSee($spk->number);
    }

    public function test_stock_valuation_uses_average_cost_with_fallbacks(): void
    {
        $fabric = Material::where('code', 'KN-CMB30-HTM')->first();
        $report = app(StockValuationReport::class);

        $materials = $report->build('materials');
        $row = $materials['rows']->firstWhere('code', 'KN-CMB30-HTM');
        $this->assertEquals(round((float) $fabric->stock * $fabric->costPrice(), 2), $row['value']);
        $this->assertEquals(round(Material::where('stock', '!=', 0)->get()->sum(fn ($m) => (float) $m->stock * $m->costPrice()), 2), $materials['totals']['value']);

        // Barang jadi belum pernah diproduksi -> HPP standar BOM
        $product = Product::where('code', 'TS-BSC')->with(['variants', 'activeBom'])->first();
        $variant = $product->variants->first();
        DB::transaction(fn () => app(StockService::class)->move($variant, 10, StockMovementType::Adjustment));

        $products = $report->build('products');
        $row = $products['rows']->firstWhere('key', $variant->id);
        $this->assertSame('standard', $row['basis']);
        $this->assertEquals(round(10 * $product->activeBom->standardUnitCost(), 2), $row['value']);
        $this->assertEquals(round(10 * $variant->selling_price, 2), $row['selling_value']);

        $this->get(route('reports.stock-valuation'))->assertOk()->assertSee('KN-CMB30-HTM');
        $this->get(route('reports.stock-valuation', ['type' => 'products']))->assertOk()->assertSee($variant->sku);
    }

    public function test_reports_export_to_excel_and_pdf(): void
    {
        $this->invoicedSale();

        $response = $this->get(route('reports.sales', ['from' => '2026-10-01', 'to' => '2026-10-31', 'export' => 'xlsx']));
        $response->assertOk()->assertDownload();
        $this->assertStringContainsString('spreadsheetml', $response->headers->get('content-type'));

        // File .xlsx valid dan berisi data laporan
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->getFile()->getPathname()));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertNotFalse(simplexml_load_string($sheet));
        $this->assertStringContainsString(Customer::first()->name, $sheet);
        $this->assertStringContainsString('<v>1920000</v>', $sheet);

        foreach ([
            route('reports.sales', ['export' => 'pdf', 'group' => 'item']),
            route('reports.purchases', ['export' => 'pdf']),
            route('reports.production', ['export' => 'pdf', 'detail' => 'orders']),
            route('reports.stock-valuation', ['export' => 'pdf', 'type' => 'products']),
        ] as $url) {
            $this->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');
        }

        foreach ([route('reports.production', ['export' => 'xlsx']), route('reports.stock-valuation', ['export' => 'xlsx'])] as $url) {
            $this->get($url)->assertOk()->assertDownload();
        }
    }

    public function test_dashboard_shows_summary_for_role(): void
    {
        $this->invoicedSale();
        $this->billedPurchase();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Penjualan terfaktur bulan ini')
            ->assertSee('Rp 1.920.000')
            ->assertSee('Hutang supplier')
            ->assertSee('Nilai persediaan')
            ->assertSee('Pelanggan teratas bulan ini');

        // Gudang tidak melihat angka keuangan maupun nilai persediaan
        $this->actingAs(User::factory()->create(['role' => Role::Gudang]));
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Penjualan terfaktur bulan ini')
            ->assertDontSee('Hutang supplier')
            ->assertDontSee('Nilai persediaan');
    }

    public function test_report_permissions_follow_roles(): void
    {
        $this->actingAs(User::factory()->create(['role' => Role::Gudang]));
        $this->get(route('reports.sales'))->assertForbidden();
        $this->get(route('reports.purchases'))->assertForbidden();
        $this->get(route('reports.stock-valuation'))->assertForbidden();
        $this->get(route('reports.production'))->assertOk();

        $this->actingAs(User::factory()->create(['role' => Role::Keuangan]));
        $this->get(route('reports.sales'))->assertOk();
        $this->get(route('reports.purchases'))->assertOk();
        $this->get(route('reports.stock-valuation'))->assertOk();
        $this->get(route('reports.production'))->assertForbidden();
    }
}
