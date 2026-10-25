<?php

namespace Tests\Feature;

use App\Models\Bom;
use App\Models\Customer;
use App\Models\Material;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pastikan semua halaman utama dapat dirender tanpa error.
 */
class PagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_pages_render(): void
    {
        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create());

        $product = Product::first();
        $material = Material::first();

        $urls = [
            route('dashboard'),
            route('units.index'), route('units.create'),
            route('categories.index'), route('categories.create'),
            route('suppliers.index'), route('suppliers.create'), route('suppliers.edit', Supplier::first()),
            route('customers.index'), route('customers.create'), route('customers.edit', Customer::first()),
            route('materials.index'), route('materials.create'), route('materials.edit', $material),
            route('products.index'), route('products.create'), route('products.show', $product), route('products.edit', $product),
            route('boms.index'), route('boms.create'), route('boms.show', Bom::first()), route('boms.edit', Bom::first()),
            route('purchase-orders.index'), route('purchase-orders.create'),
            route('sales-orders.index'), route('sales-orders.create'),
            route('production-orders.index'), route('production-orders.create'),
            route('stock.materials'), route('stock.products'), route('stock.adjustment'),
            route('stock.materials.card', $material), route('stock.products.card', $product->variants->first()),
            route('mrp.index'), route('reports.costing'),
        ];

        foreach ($urls as $url) {
            $this->get($url)->assertOk();
        }
    }
}
