<?php

namespace Tests\Feature;

use App\Enums\ProductionStage;
use App\Enums\StageStatus;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductStageRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_product_stage_route_is_saved_in_production_order_order(): void
    {
        $this->get(route('products.create'))->assertOk()->assertSee('Sablon / Printing')->assertSee('Bordir');

        $this->post(route('products.store'), [
            'code' => 'TS-SBL', 'name' => 'Kaos Sablon', 'unit_id' => Unit::first()->id, 'price' => 80000, 'is_active' => 1,
            // Urutan kiriman acak, disimpan sesuai alur produksi
            'stage_route' => ['packing', 'printing', 'cutting', 'sewing', 'qc'],
            'variants' => [['sku' => 'TS-SBL-M', 'size' => 'M']],
        ])->assertSessionHasNoErrors();

        $product = Product::where('code', 'TS-SBL')->first();
        $this->assertSame(['cutting', 'printing', 'sewing', 'qc', 'packing'], $product->stage_route);

        // Tahap standar disimpan sebagai null
        $this->put(route('products.update', $product), [
            'code' => 'TS-SBL', 'name' => 'Kaos Sablon', 'unit_id' => Unit::first()->id,
            'stage_route' => array_map(fn ($s) => $s->value, ProductionStage::standard()),
            'variants' => [['id' => $product->variants()->first()->id, 'sku' => 'TS-SBL-M', 'size' => 'M']],
        ])->assertSessionHasNoErrors();
        $this->assertNull($product->fresh()->stage_route);
        $this->assertSame(ProductionStage::standard(), $product->fresh()->stages());
    }

    public function test_spk_stages_follow_product_route(): void
    {
        $product = Product::where('code', 'TS-BSC')->with('variants')->first();
        $product->update(['stage_route' => ['cutting', 'printing', 'sewing', 'washing', 'finishing', 'qc', 'packing']]);

        $this->post(route('production-orders.store'), [
            'product_id' => $product->id, 'start_date' => '2026-10-29', 'items' => [$product->variants[0]->id => 10],
        ])->assertSessionHasNoErrors();
        $spk = ProductionOrder::first();

        $this->assertSame(
            ['cutting', 'printing', 'sewing', 'washing', 'finishing', 'qc', 'packing'],
            $spk->stages()->get()->map(fn ($s) => $s->stage->value)->all(),
        );
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $spk->stages()->pluck('sequence')->all());

        // Alur artikel diubah selama SPK masih draft → tahap disusun ulang saat SPK disimpan
        $product->update(['stage_route' => ['cutting', 'embroidery', 'sewing', 'qc', 'packing']]);
        $this->put(route('production-orders.update', $spk), [
            'product_id' => $product->id, 'start_date' => '2026-10-29', 'items' => [$product->variants[0]->id => 10],
        ])->assertSessionHasNoErrors();
        $this->assertSame(['cutting', 'embroidery', 'sewing', 'qc', 'packing'], $spk->stages()->get()->map(fn ($s) => $s->stage->value)->all());

        // Tahap tambahan berjalan seperti tahap lain
        $this->post(route('production-orders.start', $spk))->assertSessionHasNoErrors();
        $this->get(route('production-orders.show', $spk))->assertOk()->assertSee('Bordir');
        foreach ($spk->stages()->get() as $stage) {
            $this->put(route('production-orders.stages.update', [$spk, $stage]), ['status' => StageStatus::Done->value, 'output_quantity' => 10])->assertSessionHasNoErrors();
        }
        $this->post(route('production-orders.complete', $spk), ['finished' => [$spk->items()->first()->id => 10]])->assertSessionHasNoErrors();
    }
}
