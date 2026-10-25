<?php

namespace Tests\Feature;

use App\Enums\ProductionOrderStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\StageStatus;
use App\Models\Customer;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GarmentFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_purchase_order_receipt_increases_material_stock(): void
    {
        $material = Material::where('code', 'KN-CMB30-HTM')->first();
        $before = (float) $material->stock;

        $this->post(route('purchase-orders.store'), [
            'supplier_id' => Supplier::first()->id,
            'order_date' => '2026-10-24',
            'items' => [['material_id' => $material->id, 'quantity' => 100, 'price' => 120000]],
        ])->assertRedirect();

        $po = PurchaseOrder::first();
        $this->assertSame('PO-202610-0001', $po->number);
        $this->get(route('purchase-orders.show', $po))->assertOk();
        $this->get(route('purchase-orders.edit', $po))->assertOk();
        $this->assertEquals(12000000, (float) $po->total);

        $this->post(route('purchase-orders.submit', $po));
        $this->get(route('purchase-orders.receive', $po))->assertOk();
        $item = $po->items()->first();

        // Penerimaan sebagian
        $this->post(route('purchase-orders.receive.store', $po), [
            'receipt_date' => '2026-10-25',
            'items' => [$item->id => 40],
        ])->assertRedirect(route('purchase-orders.show', $po));

        $this->assertSame(PurchaseOrderStatus::Partial, $po->fresh()->status);
        $this->assertEquals($before + 40, (float) $material->fresh()->stock);

        // Tidak boleh melebihi sisa
        $this->post(route('purchase-orders.receive.store', $po), [
            'receipt_date' => '2026-10-25',
            'items' => [$item->id => 100],
        ])->assertSessionHasErrors();

        $this->post(route('purchase-orders.receive.store', $po), [
            'receipt_date' => '2026-10-26',
            'items' => [$item->id => 60],
        ]);

        $this->assertSame(PurchaseOrderStatus::Received, $po->fresh()->status);
        $this->assertEquals($before + 100, (float) $material->fresh()->stock);
        $this->assertSame(2, $material->stockMovements()->where('type', 'purchase_receipt')->count());
    }

    public function test_full_sales_to_production_to_delivery_flow(): void
    {
        $product = Product::where('code', 'TS-BSC')->with('variants')->first();
        [$s, $m] = [$product->variants[0], $product->variants[1]];
        $fabric = Material::where('code', 'KN-CMB30-HTM')->first();
        $fabricBefore = (float) $fabric->stock;

        // 1. Sales order
        $this->post(route('sales-orders.store'), [
            'customer_id' => Customer::first()->id,
            'order_date' => '2026-10-24',
            'discount' => 50000,
            'items' => [
                ['product_variant_id' => $s->id, 'quantity' => 30, 'price' => 65000],
                ['product_variant_id' => $m->id, 'quantity' => 20, 'price' => 65000],
            ],
        ])->assertRedirect();

        $so = SalesOrder::first();
        $this->assertEquals(3250000 - 50000, (float) $so->total);
        $this->post(route('sales-orders.confirm', $so));
        $this->assertSame(SalesOrderStatus::Confirmed, $so->fresh()->status);

        // Belum ada stok barang jadi -> pengiriman ditolak
        $soItems = $so->items()->get()->keyBy('product_variant_id');
        $this->post(route('sales-orders.deliver.store', $so), [
            'delivery_date' => '2026-10-24',
            'items' => [$soItems[$s->id]->id => 30],
        ])->assertSessionHasErrors('stock');

        // 2. SPK produksi dari SO
        $this->post(route('production-orders.store'), [
            'product_id' => $product->id,
            'sales_order_id' => $so->id,
            'start_date' => '2026-10-24',
            'items' => [$s->id => 30, $m->id => 20],
        ])->assertRedirect();

        $spk = ProductionOrder::with('materials', 'stages')->first();
        $this->assertEquals(50, (float) $spk->quantity);
        $this->assertCount(5, $spk->stages);

        // Kebutuhan kain = 0.22 * 50 * 1.08 = 11.88
        $fabricLine = $spk->materials->firstWhere('material_id', $fabric->id);
        $this->assertEquals(11.88, (float) $fabricLine->required_quantity);

        // 3. Mulai produksi -> bahan keluar
        $this->post(route('production-orders.start', $spk))->assertSessionHasNoErrors();
        $this->assertSame(ProductionOrderStatus::InProgress, $spk->fresh()->status);
        $this->get(route('production-orders.show', $spk))->assertOk()->assertSee('Tahapan Produksi');
        $this->assertEquals(round($fabricBefore - 11.88, 2), (float) $fabric->fresh()->stock);

        // Tidak bisa selesai sebelum semua tahapan selesai
        $this->post(route('production-orders.complete', $spk), ['finished' => [0 => 1]])->assertSessionHasErrors('status');

        // Tahapan harus berurutan
        $stages = $spk->stages()->get();
        $this->put(route('production-orders.stages.update', [$spk, $stages[1]]), ['status' => 'done'])->assertSessionHasErrors('status');

        foreach ($stages as $stage) {
            $this->put(route('production-orders.stages.update', [$spk, $stage]), [
                'status' => StageStatus::Done->value, 'output_quantity' => 50,
            ])->assertSessionHasNoErrors();
        }

        // 4. Selesaikan produksi -> barang jadi masuk
        $items = $spk->items()->get()->keyBy('product_variant_id');
        $this->post(route('production-orders.complete', $spk), [
            'finished' => [$items[$s->id]->id => 30, $items[$m->id]->id => 20],
        ])->assertSessionHasNoErrors();

        $this->assertSame(ProductionOrderStatus::Completed, $spk->fresh()->status);
        $this->assertEquals(30, (float) $s->fresh()->stock);
        $this->assertEquals(20, (float) $m->fresh()->stock);

        // 5. Kirim SO bertahap (surat jalan) -> stok barang jadi berkurang
        $this->get(route('sales-orders.deliver', $so))->assertOk();
        $this->post(route('sales-orders.deliver.store', $so), [
            'delivery_date' => '2026-10-27',
            'vehicle_number' => 'D 1234 AB',
            'items' => [$soItems[$s->id]->id => 30],
        ])->assertSessionHasNoErrors();
        $this->assertSame(SalesOrderStatus::Partial, $so->fresh()->status);
        $this->assertEquals(0, (float) $s->fresh()->stock);

        // Tidak boleh melebihi sisa pesanan
        $this->post(route('sales-orders.deliver.store', $so), [
            'delivery_date' => '2026-10-27',
            'items' => [$soItems[$m->id]->id => 25],
        ])->assertSessionHasErrors();

        $this->post(route('sales-orders.deliver.store', $so), [
            'delivery_date' => '2026-10-28',
            'items' => [$soItems[$m->id]->id => 20],
        ])->assertSessionHasNoErrors();
        $this->assertSame(SalesOrderStatus::Delivered, $so->fresh()->status);
        $this->assertSame(['SJ-202610-0001', 'SJ-202610-0002'], $so->deliveries()->orderBy('id')->pluck('number')->all());
        $this->assertSame('delivery', $s->stockMovements()->where('type', 'sales_delivery')->value('reference_type'));
        $this->get(route('sales-orders.show', $so))->assertOk()->assertSee($spk->number);
        $this->get(route('production-orders.show', $spk))->assertOk();
        $this->get(route('dashboard'))->assertOk();
        $this->assertEquals(0, (float) $s->fresh()->stock);
        $this->assertEquals(0, (float) $m->fresh()->stock);
    }

    public function test_production_cannot_start_without_enough_material(): void
    {
        $product = Product::where('code', 'KMJ-OXF')->with('variants')->first();

        // Kain oxford stok 400 m, kebutuhan 1.76 m/pcs -> 500 pcs butuh 880 m
        $this->post(route('production-orders.store'), [
            'product_id' => $product->id,
            'start_date' => '2026-10-24',
            'items' => [$product->variants[0]->id => 500],
        ]);

        $spk = ProductionOrder::first();
        $this->post(route('production-orders.start', $spk))->assertSessionHasErrors('stock');
        $this->assertSame(ProductionOrderStatus::Draft, $spk->fresh()->status);
        $this->assertEquals(400, (float) Material::where('code', 'KN-OXF-BRU')->value('stock'));
    }

    public function test_stock_adjustment_sets_physical_count(): void
    {
        $material = Material::where('code', 'KNC-12MM')->first();

        $this->post(route('stock.adjustment.store'), [
            'item' => 'material:'.$material->id,
            'date' => '2026-10-24',
            'mode' => 'set',
            'quantity' => 4800,
            'notes' => 'Stock opname',
        ])->assertRedirect(route('stock.materials.card', $material->id));

        $this->assertEquals(4800, (float) $material->fresh()->stock);
        $this->assertEquals(-200, (float) $material->stockMovements()->latest('id')->first()->quantity);
    }

    public function test_product_with_variants_can_be_created_and_updated(): void
    {
        $unitId = Unit::first()->id;

        $this->post(route('products.store'), [
            'code' => 'HD-001', 'name' => 'Hoodie Polos', 'unit_id' => $unitId, 'price' => 150000, 'is_active' => 1,
            'variants' => [
                ['sku' => 'HD-001-M', 'size' => 'M'],
                ['sku' => 'HD-001-L', 'size' => 'L'],
            ],
        ])->assertRedirect(route('products.index'));

        $product = Product::where('code', 'HD-001')->first();
        $this->assertCount(2, $product->variants);
        $m = $product->variants->firstWhere('size', 'M');

        $this->put(route('products.update', $product), [
            'code' => 'HD-001', 'name' => 'Hoodie Polos', 'unit_id' => $unitId, 'price' => 150000,
            'variants' => [
                ['id' => $m->id, 'sku' => 'HD-001-M', 'size' => 'M', 'price' => 160000],
                ['sku' => 'HD-001-XL', 'size' => 'XL'],
            ],
        ])->assertRedirect(route('products.index'));

        $product->refresh();
        $this->assertEqualsCanonicalizing(['M', 'XL'], $product->variants->pluck('size')->all());
        $this->assertEquals(160000, (float) $product->variants->firstWhere('size', 'M')->price);
        $this->assertFalse($product->is_active);
    }
}
