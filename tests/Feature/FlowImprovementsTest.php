<?php

namespace Tests\Feature;

use App\Enums\PurchaseOrderStatus;
use App\Enums\Role;
use App\Enums\SalesOrderStatus;
use App\Enums\StockMovementType;
use App\Models\Customer;
use App\Models\JournalLine;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Reports\StockValuationReport;
use App\Services\StockService;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FlowImprovementsTest extends TestCase
{
    use RefreshDatabase;

    private function salesOrder(array $lines): SalesOrder
    {
        $this->post(route('sales-orders.store'), [
            'customer_id' => Customer::first()->id,
            'order_date' => now()->toDateString(),
            'items' => collect($lines)->map(fn ($qty, $variantId) => ['product_variant_id' => $variantId, 'quantity' => $qty, 'price' => 65000])->values()->all(),
        ])->assertSessionHasNoErrors();

        $order = SalesOrder::latest('id')->first();
        $this->post(route('sales-orders.confirm', $order));

        return $order->fresh('items');
    }

    public function test_partially_delivered_sales_order_can_be_closed_with_reason(): void
    {
        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create());
        $variant = ProductVariant::where('sku', 'TS-BSC-HTM-M')->first();
        DB::transaction(fn () => app(StockService::class)->move($variant, 30, StockMovementType::Adjustment, unitCost: 30000));

        $order = $this->salesOrder([$variant->id => 50]);
        $item = $order->items->first();

        // SO yang belum dikirim tidak bisa ditutup (pakai batalkan)
        $this->post(route('sales-orders.close', $order), ['reason' => 'x'])->assertForbidden();

        $this->post(route('sales-orders.deliver.store', $order), ['delivery_date' => now()->toDateString(), 'items' => [$item->id => 30]])->assertSessionHasNoErrors();
        $this->assertSame(SalesOrderStatus::Partial, $order->fresh()->status);
        $this->get(route('sales-orders.show', $order))->assertOk()->assertSee('Tutup Sisa SO');

        $this->post(route('sales-orders.close', $order))->assertSessionHasErrors('reason');
        $this->post(route('sales-orders.close', $order), ['reason' => 'Pelanggan batal ambil sisa'])->assertSessionHasNoErrors();

        $order->refresh();
        $this->assertSame(SalesOrderStatus::Closed, $order->status);
        $this->assertFalse($order->canDeliver());
        $this->assertTrue($order->fresh('items')->canInvoice());
        $this->get(route('sales-orders.index', ['status' => 'open']))->assertOk()->assertDontSee($order->number);
        $this->get(route('mrp.index'))->assertOk()->assertDontSee($order->number);
    }

    public function test_partially_received_purchase_order_can_be_closed(): void
    {
        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create());
        $material = Material::where('code', 'BNG-POL-PTH')->first();

        $this->post(route('purchase-orders.store'), [
            'supplier_id' => Supplier::first()->id,
            'order_date' => now()->toDateString(),
            'items' => [['material_id' => $material->id, 'quantity' => 20, 'price' => 18000]],
        ])->assertSessionHasNoErrors();
        $po = PurchaseOrder::latest('id')->first();
        $this->post(route('purchase-orders.submit', $po));
        $this->post(route('purchase-orders.receive.store', $po), ['receipt_date' => now()->toDateString(), 'items' => [$po->items()->first()->id => 12]])
            ->assertSessionHasNoErrors();

        $this->get(route('purchase-orders.index', ['status' => 'open']))->assertOk()->assertSee($po->number);
        $this->post(route('purchase-orders.close', $po), ['reason' => 'Supplier kehabisan stok'])->assertSessionHasNoErrors();
        $this->assertSame(PurchaseOrderStatus::Closed, $po->fresh()->status);
        $this->assertFalse($po->fresh()->canReceive());
        $this->get(route('purchase-orders.index', ['status' => 'open']))->assertOk()->assertDontSee($po->number);
    }

    public function test_spk_from_sales_order_prefills_product_and_remaining_quantity(): void
    {
        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create());
        $kaos = Product::where('code', 'TS-BSC')->first();
        $s = $kaos->variants->firstWhere('size', 'S');
        $m = $kaos->variants->firstWhere('size', 'M');
        $order = $this->salesOrder([$s->id => 40, $m->id => 60]);

        $this->get(route('production-orders.create', ['sales_order_id' => $order->id]))
            ->assertOk()
            ->assertViewHas('order', fn ($spk) => $spk->product_id === $kaos->id)
            ->assertViewHas('soRemaining', fn ($rows) => $rows[$order->id] == [$s->id => 40.0, $m->id => 60.0]);

        // SPK pertama hanya 30 pcs ukuran M → sisa saran: S 40, M 30
        $this->post(route('production-orders.store'), [
            'product_id' => $kaos->id, 'sales_order_id' => $order->id, 'start_date' => now()->toDateString(), 'items' => [$m->id => 30],
        ])->assertSessionHasNoErrors();

        $this->get(route('production-orders.create', ['sales_order_id' => $order->id]))
            ->assertViewHas('soRemaining', fn ($rows) => $rows[$order->id] == [$s->id => 40.0, $m->id => 30.0]);
    }

    public function test_dashboard_lists_next_steps_per_role(): void
    {
        $this->seed(MasterDataSeeder::class);
        $variant = ProductVariant::where('sku', 'TS-BSC-HTM-L')->first();
        DB::transaction(fn () => app(StockService::class)->move($variant, 10, StockMovementType::Adjustment, unitCost: 30000));

        $this->actingAs(User::factory()->role(Role::Sales)->create());
        $order = $this->salesOrder([$variant->id => 10]);
        $this->post(route('sales-orders.deliver.store', $order), ['delivery_date' => now()->toDateString(), 'items' => [$order->items->first()->id => 10]])
            ->assertForbidden();

        $this->actingAs(User::factory()->role(Role::Gudang)->create());
        $this->get(route('dashboard'))->assertOk()->assertSee('Perlu Ditindaklanjuti')->assertSee('SO menunggu pengiriman');
        $this->post(route('sales-orders.deliver.store', $order), ['delivery_date' => now()->toDateString(), 'items' => [$order->items->first()->id => 10]])
            ->assertSessionHasNoErrors();

        $this->actingAs(User::factory()->role(Role::Keuangan)->create());
        $this->get(route('dashboard'))->assertOk()->assertSee('SO terkirim belum diinvoice')->assertDontSee('SO menunggu pengiriman');

        $this->actingAs(User::factory()->role(Role::Sales)->create());
        $this->get(route('dashboard'))->assertOk()->assertDontSee('SO terkirim belum diinvoice');
    }

    public function test_demo_seeder_runs_full_flow_with_balanced_ledger(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(4, SalesOrder::count());
        $this->assertSame(0.0, round((float) JournalLine::sum(DB::raw('debit - credit')), 2));

        // Persediaan di buku besar = nilai persediaan dari kartu stok
        $inventory = (float) JournalLine::whereHas('account', fn ($q) => $q->whereIn('code', ['1301', '1303', '1305']))->sum(DB::raw('debit - credit'));
        $this->assertEqualsWithDelta(array_sum(app(StockValuationReport::class)->grandTotal()), $inventory, 0.05);

        foreach (['admin', 'purchasing', 'gudang', 'produksi', 'sales', 'keuangan'] as $role) {
            $this->actingAs(User::where('email', "{$role}@erp.test")->first());
            $this->get(route('dashboard'))->assertOk()->assertSee('Perlu Ditindaklanjuti');
        }

        $this->actingAs(User::where('email', 'admin@erp.test')->first());
        foreach (SalesOrder::all() as $so) {
            $this->get(route('sales-orders.show', $so))->assertOk();
        }
        $this->get(route('reports.trial-balance'))->assertOk();
        $this->get(route('reports.sales'))->assertOk()->assertSee('PT Seragam Mandiri');
    }
}
