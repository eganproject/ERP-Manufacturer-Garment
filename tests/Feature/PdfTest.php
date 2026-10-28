<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Models\Bom;
use App\Models\Customer;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\StockService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_documents_can_be_printed(): void
    {
        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create());

        $material = Material::first();
        $product = Product::where('code', 'TS-BSC')->with('variants')->first();
        $variant = $product->variants->first();

        // PO + penerimaan
        $this->post(route('purchase-orders.store'), [
            'supplier_id' => Supplier::first()->id, 'order_date' => '2026-10-24',
            'items' => [['material_id' => $material->id, 'quantity' => 10, 'price' => 125000]],
        ]);
        $po = PurchaseOrder::first();
        $this->post(route('purchase-orders.submit', $po));
        $this->post(route('purchase-orders.receive.store', $po), ['receipt_date' => '2026-10-25', 'items' => [$po->items()->first()->id => 10]]);

        // SO + surat jalan
        DB::transaction(fn () => app(StockService::class)->move($variant, 10, StockMovementType::Adjustment));
        $this->post(route('sales-orders.store'), [
            'customer_id' => Customer::first()->id, 'order_date' => '2026-10-24',
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 5, 'price' => 65000]],
        ]);
        $so = SalesOrder::first();
        $this->post(route('sales-orders.confirm', $so));
        $this->post(route('sales-orders.deliver.store', $so), ['delivery_date' => '2026-10-26', 'items' => [$so->items()->first()->id => 5]]);

        // SPK
        $this->post(route('production-orders.store'), [
            'product_id' => $product->id, 'start_date' => '2026-10-24', 'items' => [$variant->id => 12],
        ]);
        $spk = ProductionOrder::first();

        $urls = [
            route('boms.pdf', [Bom::first(), 'qty' => 120]),
            route('purchase-orders.pdf', $po),
            route('goods-receipts.pdf', $po->receipts()->first()),
            route('sales-orders.pdf', $so),
            route('deliveries.pdf', $so->deliveries()->first()),
            route('production-orders.pdf', $spk),
        ];

        foreach ($urls as $url) {
            $response = $this->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $response->getContent());
        }

        // Riwayat aktivitas tampil di halaman dokumen
        $this->get(route('sales-orders.show', $so))->assertSee('Mengonfirmasi SO')->assertSee('SJ-202610-0001');
        $this->get(route('activity-logs.index'))->assertOk()->assertSee('Membuat PO');

        // Peran tanpa akses pembelian tidak bisa mencetak PO
        $this->actingAs(User::factory()->role(Role::Sales)->create());
        $this->get(route('purchase-orders.pdf', $po))->assertForbidden();
        $this->get(route('sales-orders.pdf', $so))->assertOk();
    }
}
