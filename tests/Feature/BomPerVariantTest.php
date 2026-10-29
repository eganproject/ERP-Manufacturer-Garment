<?php

namespace Tests\Feature;

use App\Models\Bom;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BomPerVariantTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create());

        $this->product = Product::where('code', 'TS-BSC')->first();
        foreach (['M', 'XL'] as $size) {
            $this->product->variants()->create(['sku' => "TS-BSC-PTH-{$size}", 'size' => $size, 'color' => 'Putih']);
        }
    }

    private function material(string $code): Material
    {
        return Material::where('code', $code)->firstOrFail();
    }

    private function storeBom(array $items)
    {
        return $this->post(route('boms.store'), [
            'product_id' => $this->product->id, 'name' => 'BOM per warna', 'is_active' => 1,
            'labor_cost' => 6000, 'overhead_cost' => 2500, 'items' => $items,
        ]);
    }

    public function test_bom_lines_can_be_limited_to_sizes_and_colors(): void
    {
        $black = $this->material('KN-CMB30-HTM');
        $white = $this->material('KN-CMB30-PTH');
        $label = $this->material('LBL-WOVEN');

        $this->get(route('boms.create'))->assertOk()->assertSee('Khusus warna');

        // Ukuran / warna yang tidak ada di varian ditolak
        $this->storeBom([
            ['material_id' => $black->id, 'quantity' => 0.2, 'colors' => 'Merah'],
        ])->assertSessionHasErrors('items.0.colors');

        $this->storeBom([
            ['material_id' => $black->id, 'quantity' => 0.2, 'colors' => 'hitam', 'sizes' => 'S, M, L'],
            ['material_id' => $black->id, 'quantity' => 0.25, 'colors' => 'Hitam', 'sizes' => 'XL;XXL'],
            ['material_id' => $white->id, 'quantity' => 0.2, 'colors' => 'Putih'],
            ['material_id' => $label->id, 'quantity' => 1],
        ])->assertSessionHasNoErrors();

        $bom = Bom::where('name', 'BOM per warna')->with('items')->first();
        $this->assertSame(['Hitam'], $bom->items[0]->colors);
        $this->assertSame(['S', 'M', 'L'], $bom->items[0]->sizes);
        $this->assertSame(['XL', 'XXL'], $bom->items[1]->sizes);
        $this->assertNull($bom->items[3]->sizes);
        $this->assertTrue($bom->isPerVariant());

        $this->get(route('boms.show', $bom))->assertOk()->assertSee('HPP standar per varian')->assertSee('Ukuran XL, XXL');
        $this->get(route('boms.pdf', $bom))->assertOk();

        // HPP standar per varian berbeda
        $variants = $this->product->variants()->get()->keyBy('sku');
        $this->assertEqualsWithDelta(0.25 * $black->costPrice() + $label->costPrice(), $bom->standardMaterialCost($variants['TS-BSC-HTM-XL']), 0.01);
        $this->assertEqualsWithDelta(0.2 * $white->costPrice() + $label->costPrice(), $bom->standardMaterialCost($variants['TS-BSC-PTH-M']), 0.01);

        // SPK: kebutuhan bahan dihitung per varian
        $this->post(route('production-orders.store'), [
            'product_id' => $this->product->id,
            'start_date' => '2026-10-29',
            'items' => [
                $variants['TS-BSC-HTM-M']->id => 10,
                $variants['TS-BSC-HTM-XL']->id => 4,
                $variants['TS-BSC-PTH-XL']->id => 6,
            ],
        ])->assertSessionHasNoErrors();

        $spk = ProductionOrder::latest('id')->first();
        $required = $spk->materials()->pluck('required_quantity', 'material_id');
        $this->assertEquals(round(10 * 0.2 + 4 * 0.25, 2), (float) $required[$black->id]);
        $this->assertEquals(round(6 * 0.2, 2), (float) $required[$white->id]);
        $this->assertEquals(20, (float) $required[$label->id]);

        $summary = $spk->costSummary();
        $expected = 10 * $bom->standardMaterialCost($variants['TS-BSC-HTM-M']) + 4 * $bom->standardMaterialCost($variants['TS-BSC-HTM-XL']) + 6 * $bom->standardMaterialCost($variants['TS-BSC-PTH-XL']);
        $this->assertEqualsWithDelta($expected, $summary['standard_material'], 0.5);
    }

    public function test_bom_without_filters_works_as_before(): void
    {
        $bom = $this->product->activeBom()->with('items')->first();
        $this->assertFalse($bom->isPerVariant());
        $variant = $this->product->variants()->first();

        $this->assertEquals($bom->requirementsFor(10), $bom->requirementsForVariants([$variant->id => 10]));
        $this->assertEquals($bom->standardMaterialCost(), $bom->standardMaterialCost($variant));
    }
}
