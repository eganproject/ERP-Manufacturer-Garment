<?php

namespace Tests\Feature;

use App\Enums\ProductionCostType;
use App\Enums\PurchaseOrderStatus;
use App\Enums\Role;
use App\Enums\StageStatus;
use App\Models\Customer;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CostingAndMrpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_goods_receipt_updates_moving_average_cost(): void
    {
        // Stok awal 200 kg @125.000
        $fabric = Material::where('code', 'KN-CMB30-HTM')->first();
        $this->assertEquals(125000, $fabric->costPrice());

        $this->post(route('purchase-orders.store'), [
            'supplier_id' => Supplier::first()->id,
            'order_date' => '2026-10-24',
            'items' => [['material_id' => $fabric->id, 'quantity' => 100, 'price' => 131000]],
        ]);
        $po = PurchaseOrder::first();
        $this->post(route('purchase-orders.submit', $po));
        $this->post(route('purchase-orders.receive.store', $po), [
            'receipt_date' => '2026-10-25',
            'items' => [$po->items()->first()->id => 100],
        ])->assertSessionHasNoErrors();

        // (200 × 125.000 + 100 × 131.000) / 300 = 127.000
        $this->assertEquals(127000, (float) $fabric->fresh()->average_cost);
        $this->assertEquals(131000, (float) $fabric->stockMovements()->where('type', 'purchase_receipt')->value('unit_cost'));
    }

    public function test_production_order_records_actual_cost_and_unit_hpp(): void
    {
        $product = Product::where('code', 'TS-BSC')->with(['variants', 'activeBom'])->first();
        $variant = $product->variants[0];
        $standard = $product->activeBom->standardUnitCost();

        $this->post(route('production-orders.store'), [
            'product_id' => $product->id,
            'start_date' => '2026-10-24',
            'items' => [$variant->id => 100],
        ]);
        $spk = ProductionOrder::first();

        $this->get(route('production-orders.show', $spk))->assertOk()->assertSee('Biaya Produksi (HPP)');
        $projected = $spk->costSummary();
        $this->assertEquals(round($product->activeBom->standardMaterialCost() * 100, 2), $projected['standard_material']);

        // Mulai: bahan keluar dengan harga pokok, upah & overhead standar BOM dibebankan
        $this->post(route('production-orders.start', $spk))->assertSessionHasNoErrors();
        $spk->refresh();
        $fabricLine = $spk->materials()->whereHas('material', fn ($q) => $q->where('code', 'KN-CMB30-HTM'))->first();
        $this->assertEquals(125000, (float) $fabricLine->unit_cost);
        $this->assertEquals(600000, (float) $spk->costs()->where('type', ProductionCostType::Labor)->sum('amount'));
        $this->assertEquals(250000, (float) $spk->costs()->where('type', ProductionCostType::Overhead)->sum('amount'));

        // Tambah biaya CMT bordir lalu hapus satu biaya keliru
        $this->post(route('production-orders.costs.store', $spk), [
            'type' => 'cmt', 'description' => 'Bordir logo', 'amount' => 150000, 'date' => '2026-10-25',
        ])->assertSessionHasNoErrors();
        $this->post(route('production-orders.costs.store', $spk), [
            'type' => 'other', 'description' => 'Salah input', 'amount' => 99999, 'date' => '2026-10-25',
        ]);
        $wrong = $spk->costs()->where('description', 'Salah input')->first();
        $this->delete(route('production-orders.costs.destroy', [$spk, $wrong]))->assertSessionHasNoErrors();
        $this->assertSame(3, $spk->costs()->count());

        foreach ($spk->stages()->get() as $stage) {
            $this->put(route('production-orders.stages.update', [$spk, $stage]), ['status' => StageStatus::Done->value, 'output_quantity' => 100]);
        }

        // 95 jadi, 5 reject: biaya dibebankan ke 95 pcs
        $item = $spk->items()->first();
        $this->post(route('production-orders.complete', $spk), [
            'finished' => [$item->id => 95], 'reject_quantity' => 5,
        ])->assertSessionHasNoErrors();

        $spk->refresh();
        $materialCost = round($spk->materials()->get()->sum(fn ($l) => (float) $l->issued_quantity * (float) $l->unit_cost), 2);
        $this->assertEquals($materialCost, (float) $spk->material_cost);
        $this->assertEqualsWithDelta($product->activeBom->standardMaterialCost() * 100, $materialCost, 1000);
        $this->assertEquals(1000000, (float) $spk->conversion_cost);
        $this->assertEqualsWithDelta(round(((float) $spk->material_cost + 1000000) / 95, 2), (float) $spk->unit_cost, 0.01);
        $this->assertGreaterThan($standard, (float) $spk->unit_cost);

        // Barang jadi masuk dengan HPP, biaya tidak bisa diubah lagi
        $this->assertEquals((float) $spk->unit_cost, (float) $variant->fresh()->average_cost);
        $this->post(route('production-orders.costs.store', $spk), [
            'type' => 'cmt', 'description' => 'Terlambat', 'amount' => 1000, 'date' => '2026-10-26',
        ])->assertSessionHasErrors('status');

        $this->get(route('production-orders.show', $spk))->assertOk()->assertSee(rupiah($spk->unit_cost));
        $this->get(route('reports.costing', ['from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()]))
            ->assertOk()
            ->assertSee($spk->number)
            ->assertSee(rupiah($spk->unit_cost, false));
    }

    public function test_mrp_calculates_shortage_and_creates_draft_purchase_orders(): void
    {
        $product = Product::where('code', 'KMJ-OXF')->with('variants')->first();
        $variant = $product->variants[0];

        $this->post(route('sales-orders.store'), [
            'customer_id' => Customer::first()->id,
            'order_date' => '2026-10-24',
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 300, 'price' => 175000]],
        ]);
        $so = SalesOrder::first();
        $this->post(route('sales-orders.confirm', $so));

        // Kain oxford: 300 × 1,6 × 1,10 = 528 m + stok min 100 − stok 400 = kurang 228 m
        $response = $this->get(route('mrp.index'))->assertOk()->assertSee('Kebutuhan Bahan Baku');
        $rows = $response->viewData('materials')->keyBy(fn ($row) => $row['material']->code);
        $this->assertEquals(528, $rows['KN-OXF-BRU']['required']);
        $this->assertEquals(228, $rows['KN-OXF-BRU']['shortage']);
        $this->assertEquals(0, $rows['LBL-WOVEN']['shortage']);
        $this->assertEquals(300, $response->viewData('products')->first()['net']);

        // Tanpa stok minimum kekurangan jadi 128 m
        $rows = $this->get(route('mrp.index', ['filtered' => 1, 'sales_order_ids' => [$so->id]]))
            ->viewData('materials')->keyBy(fn ($row) => $row['material']->code);
        $this->assertEquals(128, $rows['KN-OXF-BRU']['shortage']);

        // Buat PO draft: kain ke supplier tekstil, kancing ke supplier aksesoris
        $fabric = Material::where('code', 'KN-OXF-BRU')->first();
        $buttons = Material::where('code', 'KNC-12MM')->first();
        $this->post(route('mrp.purchase-orders'), [
            'lines' => [
                ['selected' => 1, 'material_id' => $fabric->id, 'supplier_id' => $fabric->default_supplier_id, 'quantity' => 228, 'price' => 32000],
                ['selected' => 1, 'material_id' => $buttons->id, 'supplier_id' => $buttons->default_supplier_id, 'quantity' => 1000, 'price' => 250],
                ['material_id' => Material::where('code', 'PLS-OPP')->value('id'), 'supplier_id' => '', 'quantity' => 5],
            ],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $orders = PurchaseOrder::with('items')->get();
        $this->assertCount(2, $orders);
        $this->assertTrue($orders->every(fn ($po) => $po->status === PurchaseOrderStatus::Draft));
        $this->assertEquals(228 * 32000, (float) $orders->firstWhere('supplier_id', $fabric->default_supplier_id)->total);

        // PO draft sudah menutup kekurangan kain
        $rows = $this->get(route('mrp.index'))->viewData('materials')->keyBy(fn ($row) => $row['material']->code);
        $this->assertEquals(0, $rows['KN-OXF-BRU']['shortage']);
        $this->assertEquals(228, $rows['KN-OXF-BRU']['draft_po']);
    }

    public function test_mrp_requires_supplier_and_subtracts_planned_production(): void
    {
        $product = Product::where('code', 'TS-BSC')->with('variants')->first();
        $variant = $product->variants[0];

        $this->post(route('sales-orders.store'), [
            'customer_id' => Customer::first()->id,
            'order_date' => '2026-10-24',
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 50, 'price' => 65000]],
        ]);
        $this->post(route('sales-orders.confirm', SalesOrder::first()));

        // SPK draft 50 pcs menutup kebutuhan barang jadi; bahannya dihitung dari SPK draft
        $this->post(route('production-orders.store'), [
            'product_id' => $product->id, 'start_date' => '2026-10-24', 'items' => [$variant->id => 50],
        ]);

        $response = $this->get(route('mrp.index'));
        $this->assertEquals(0, $response->viewData('products')->first()['net']);
        $fabric = $response->viewData('materials')->first(fn ($row) => $row['material']->code === 'KN-CMB30-HTM');
        $this->assertEquals(11.88, $fabric['from_draft_spk']);
        $this->assertEquals(0, $fabric['from_sales']);

        $this->post(route('mrp.purchase-orders'), [
            'lines' => [['selected' => 1, 'material_id' => Material::first()->id, 'supplier_id' => '', 'quantity' => 10, 'price' => 1000]],
        ])->assertSessionHasErrors('lines.0.supplier_id');
        $this->assertSame(0, PurchaseOrder::count());
    }

    public function test_mrp_and_costing_permissions(): void
    {
        $matrix = [
            'mrp.index' => [Role::Purchasing, Role::Produksi],
            'reports.costing' => [Role::Produksi, Role::Keuangan],
        ];

        foreach ([Role::Purchasing, Role::Gudang, Role::Produksi, Role::Sales, Role::Keuangan] as $role) {
            $this->actingAs(User::factory()->role($role)->create());

            foreach ($matrix as $route => $allowed) {
                $this->get(route($route))->assertStatus(in_array($role, $allowed, true) ? 200 : 403);
            }
        }

        // Produksi boleh melihat MRP tetapi tidak membuat PO
        $this->actingAs(User::factory()->role(Role::Produksi)->create());
        $this->post(route('mrp.purchase-orders'), ['lines' => []])->assertForbidden();
    }
}
