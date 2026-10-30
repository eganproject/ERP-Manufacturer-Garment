<?php

namespace Tests\Feature;

use App\Models\GoodsReceipt;
use App\Models\JournalEntry;
use App\Models\Material;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Import\MaterialImporter;
use App\Services\MrpService;
use App\Services\Reports\TradeReport;
use App\Support\UnitConversion;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PurchaseUnitConversionTest extends TestCase
{
    use RefreshDatabase;

    private Material $fabric;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create());

        // Kain Oxford: stok 400 M @32.000, dibeli per yard
        $this->fabric = Material::where('code', 'KN-OXF-BRU')->first();
        $this->put(route('materials.update', $this->fabric), [
            'code' => $this->fabric->code, 'name' => $this->fabric->name, 'unit_id' => $this->fabric->unit_id,
            'purchase_unit_id' => Unit::where('code', 'YD')->value('id'), 'purchase_conversion' => 0.9144,
            'price' => 32000, 'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $this->fabric->refresh();
    }

    public function test_standard_conversion_factors(): void
    {
        $this->assertSame(0.9144, UnitConversion::factor('YD', 'M'));
        $this->assertSame(1.093613, UnitConversion::factor('m', 'yard'));
        $this->assertSame(12.0, UnitConversion::factor('LSN', 'PCS'));
        $this->assertSame(1000.0, UnitConversion::factor('KG', 'gram'));
        $this->assertNull(UnitConversion::factor('ROLL', 'M'));
        $this->assertNull(UnitConversion::factor('KG', 'M'));

        $yd = Unit::where('code', 'YD')->value('id');
        $m = Unit::where('code', 'M')->value('id');
        $this->assertSame(0.9144, UnitConversion::mapFor(Unit::all())["{$yd}:{$m}"]);
    }

    public function test_material_requires_conversion_for_purchase_unit(): void
    {
        $this->assertTrue($this->fabric->hasPurchaseUnit());
        $this->assertEquals(0.9144, (float) $this->fabric->purchase_conversion);
        $this->get(route('materials.edit', $this->fabric))->assertOk()->assertSee('Satuan Beli');
        $this->get(route('materials.index'))->assertOk()->assertSee('Beli per YD (1 = 0,9144 M)');
        $this->get(route('purchase-orders.create'))->assertOk()->assertSee('beli YD');

        $this->put(route('materials.update', $this->fabric), [
            'code' => $this->fabric->code, 'name' => $this->fabric->name, 'unit_id' => $this->fabric->unit_id,
            'purchase_unit_id' => $this->fabric->unit_id, 'purchase_conversion' => 1,
        ])->assertSessionHasErrors('purchase_unit_id');
    }

    public function test_po_in_yards_receives_stock_and_cost_in_meters(): void
    {
        $po = $this->orderYards(100, 27432); // = Rp 30.000 / meter
        $item = $po->items()->first();

        $this->assertSame('YD', $item->unitCode());
        $this->assertEquals(0.9144, $item->factor());
        $this->assertEquals(2743200, (float) $po->total);
        $this->get(route('purchase-orders.show', $po))->assertOk()->assertSee('1 YD = 0,9144 M');
        $this->get(route('purchase-orders.receive', $po))->assertOk()->assertSee('stok bertambah dalam M');

        $this->post(route('purchase-orders.receive.store', $po), [
            'receipt_date' => '2026-10-25', 'items' => [$item->id => 100],
        ])->assertSessionHasNoErrors();

        // Stok bertambah 91,44 M; PO tercatat diterima 100 YD
        $this->assertEquals(491.44, (float) $this->fabric->fresh()->stock);
        $this->assertEquals(100, (float) $item->fresh()->received_quantity);
        $receiptItem = GoodsReceipt::first()->items()->first();
        $this->assertEquals(100, (float) $receiptItem->quantity);
        $this->assertEquals(91.44, (float) $receiptItem->stock_quantity);

        // Harga pokok per meter: (400 × 32.000 + 2.743.200) / 491,44
        $movement = $this->fabric->stockMovements()->where('type', 'purchase_receipt')->first();
        $this->assertEquals(91.44, (float) $movement->quantity);
        $this->assertEqualsWithDelta(30000, (float) $movement->unit_cost, 0.01);
        $this->assertEqualsWithDelta((12800000 + 2743200) / 491.44, (float) $this->fabric->fresh()->average_cost, 0.01);

        // Jurnal persediaan = nilai PO (qty beli × harga beli)
        $entry = JournalEntry::where('source_type', (new GoodsReceipt)->getMorphClass())->first();
        $this->assertEquals(2743200, (float) $entry->lines()->sum('debit'));

        $this->get(route('goods-receipts.pdf', GoodsReceipt::first()))->assertOk();
        $this->get(route('purchase-orders.pdf', $po))->assertOk();
    }

    public function test_purchase_return_and_cancel_convert_to_stock_unit(): void
    {
        $po = $this->orderYards(50, 27432);
        $item = $po->items()->first();
        $this->post(route('purchase-orders.receive.store', $po), ['receipt_date' => '2026-10-25', 'items' => [$item->id => 50]]);
        $stock = (float) $this->fabric->fresh()->stock; // 445,72

        $this->post(route('purchase-returns.store'), [
            'order_id' => $po->id, 'return_date' => '2026-10-26', 'reason' => 'Warna tidak sesuai',
            'items' => [$item->id => 10],
        ])->assertSessionHasNoErrors();

        $return = PurchaseReturn::first();
        $line = $return->items()->first();
        $this->assertEquals(10, (float) $line->quantity);
        $this->assertEquals(round($stock - 9.14, 2), (float) $this->fabric->fresh()->stock);
        $movement = $this->fabric->stockMovements()->where('type', 'purchase_return')->first();
        $this->assertEquals(-9.14, (float) $movement->quantity);
        $this->assertEqualsWithDelta(9.14 * (float) $movement->unit_cost, (float) $line->cost, 0.01);
        $this->get(route('purchase-returns.show', $return))->assertOk()->assertSee('10 YD');

        $this->post(route('purchase-returns.cancel', $return), ['reason' => 'Batal'])->assertSessionHasNoErrors();
        $this->assertEquals($stock, (float) $this->fabric->fresh()->stock);
    }

    public function test_stock_unit_can_still_be_chosen_on_po(): void
    {
        $po = $this->orderYards(40, 30000, $this->fabric->unit_id);
        $item = $po->items()->first();
        $this->assertSame('M', $item->unitCode());
        $this->assertEquals(1, $item->factor());

        $this->post(route('purchase-orders.receive.store', $po), ['receipt_date' => '2026-10-25', 'items' => [$item->id => 40]]);
        $this->assertEquals(440, (float) $this->fabric->fresh()->stock);
    }

    public function test_mrp_counts_open_po_in_stock_unit_and_suggests_purchase_unit(): void
    {
        $po = $this->orderYards(100, 27432);
        $this->post(route('purchase-orders.receive.store', $po), ['receipt_date' => '2026-10-25', 'items' => [$po->items()->first()->id => 50]]);

        $orders = app(MrpService::class)->createPurchaseOrders([
            ['material_id' => $this->fabric->id, 'supplier_id' => Supplier::first()->id, 'quantity' => 20, 'price' => 27432],
        ]);
        $draftItem = $orders->first()->items()->first();
        $this->assertSame('YD', $draftItem->unitCode());
        $this->assertEquals(0.9144, $draftItem->factor());

        // SPK draft kemeja 500 pcs membutuhkan kain Oxford
        $shirt = Product::where('code', 'KMJ-OXF')->with('variants')->first();
        $this->post(route('production-orders.store'), [
            'product_id' => $shirt->id, 'start_date' => '2026-10-24', 'items' => [$shirt->variants[0]->id => 500],
        ])->assertSessionHasNoErrors();

        // Sisa PO berjalan 50 YD = 45,72 M; PO draft 20 YD = 18,29 M
        $row = collect(app(MrpService::class)->calculate()['materials'])->first(fn ($r) => $r['material']->id === $this->fabric->id);
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(45.72, $row['on_order'], 0.01);
        $this->assertEqualsWithDelta(18.288, $row['draft_po'], 0.01);
        $this->assertSame('YD', $row['purchase_unit']);
        $this->assertEquals(ceil($row['shortage'] / 0.9144), $row['suggested_quantity']);
        $this->assertEquals(round(32000 * 0.9144, 2), $row['price']);
        $this->get(route('mrp.index'))->assertOk();
    }

    public function test_purchase_report_sums_quantity_in_stock_unit(): void
    {
        $po = $this->orderYards(100, 27432);
        $item = $po->items()->first();
        $this->post(route('purchase-orders.receive.store', $po), ['receipt_date' => '2026-10-25', 'items' => [$item->id => 100]]);
        $this->post(route('bills.store'), [
            'purchase_order_id' => $po->id, 'bill_date' => '2026-10-26',
            'items' => [$item->id => ['quantity' => 100, 'price' => 27432]],
        ])->assertSessionHasNoErrors();

        $report = app(TradeReport::class)->build('purchases', Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'), 'item');
        $row = $report['rows']->firstWhere('code', 'KN-OXF-BRU');
        $this->assertEquals(91.44, $row['quantity']);
        $this->assertSame('M', $row['unit']);
    }

    public function test_material_import_reads_purchase_unit(): void
    {
        $importer = app(MaterialImporter::class);
        $rows = [[
            'code' => 'KN-TWL-01', 'name' => 'Kain Twill', 'category' => null, 'unit' => 'M',
            'purchase_unit' => 'YD', 'purchase_conversion' => null, 'color' => null, 'price' => 30000,
            'min_stock' => null, 'supplier' => null, 'notes' => null, 'is_active' => null,
        ]];
        $method = new \ReflectionMethod($importer, 'save');
        $method->invoke($importer, $rows[0]);

        $material = Material::where('code', 'KN-TWL-01')->first();
        $this->assertSame('YD', $material->purchaseUnit->code);
        $this->assertEquals(0.9144, (float) $material->purchase_conversion);
    }

    private function orderYards(float $qty, float $price, ?int $unitId = null): PurchaseOrder
    {
        $this->post(route('purchase-orders.store'), [
            'supplier_id' => Supplier::first()->id,
            'order_date' => '2026-10-24',
            'items' => [['material_id' => $this->fabric->id, 'unit_id' => $unitId, 'quantity' => $qty, 'price' => $price]],
        ])->assertSessionHasNoErrors();
        $po = PurchaseOrder::latest('id')->first();
        $this->post(route('purchase-orders.submit', $po));

        return $po->fresh();
    }
}
