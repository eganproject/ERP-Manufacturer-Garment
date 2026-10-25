<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Enums\StockOpnameStatus;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockTransfer;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\AutoJournal;
use App\Services\Accounting\LedgerRebuilder;
use App\Services\StockService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WarehouseTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $main;

    private Warehouse $finished;

    private Material $fabric;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-25 10:00:00');
        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create());

        $this->main = Warehouse::default();
        $this->finished = Warehouse::create(['code' => 'GJ', 'name' => 'Gudang Barang Jadi', 'is_active' => true]);
        $this->fabric = Material::where('code', 'KN-CMB30-HTM')->first(); // saldo awal 200 KG di gudang utama
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_existing_stock_lives_in_default_warehouse_and_balance_is_checked_per_warehouse(): void
    {
        $this->assertSame(200.0, $this->fabric->stockIn($this->main));
        $this->assertSame(0.0, $this->fabric->stockIn($this->finished));

        // Total stok cukup, tapi gudang GJ kosong → ditolak
        try {
            DB::transaction(fn () => app(StockService::class)->move($this->fabric, -10, StockMovementType::Adjustment, notes: 'uji', warehouse: $this->finished));
            $this->fail('Seharusnya ditolak');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Gudang Barang Jadi', collect($e->errors())->flatten()->implode(' '));
        }

        $this->assertSame(200.0, (float) $this->fabric->fresh()->stock);
    }

    public function test_transfer_moves_stock_between_warehouses_without_journal_and_can_be_cancelled(): void
    {
        $journals = JournalEntry::count();

        $this->post(route('stock-transfers.store'), [
            'date' => '2026-10-20', 'from_warehouse_id' => $this->main->id, 'to_warehouse_id' => $this->finished->id,
            'lines' => [['item' => 'material:'.$this->fabric->id, 'quantity' => 60]],
        ])->assertSessionHasNoErrors();

        $transfer = StockTransfer::first();
        $this->assertStringStartsWith('TG-202610-', $transfer->number);
        $this->fabric->unsetRelation('warehouseStocks');
        $this->assertSame(140.0, $this->fabric->stockIn($this->main));
        $this->assertSame(60.0, $this->fabric->stockIn($this->finished));
        $this->assertSame(200.0, (float) $this->fabric->fresh()->stock);
        $this->assertSame($journals, JournalEntry::count());

        $last = StockMovement::where('stockable_id', $this->fabric->id)->where('stockable_type', 'material')->latest('id')->first();
        $this->assertSame($this->finished->id, $last->warehouse_id);
        $this->assertSame(60.0, (float) $last->warehouse_balance);

        // Lebih dari stok gudang asal → ditolak
        $this->post(route('stock-transfers.store'), [
            'date' => '2026-10-20', 'from_warehouse_id' => $this->finished->id, 'to_warehouse_id' => $this->main->id,
            'lines' => [['item' => 'material:'.$this->fabric->id, 'quantity' => 61]],
        ])->assertSessionHasErrors();

        $this->get(route('stock-transfers.show', $transfer))->assertOk()->assertSee($transfer->number);
        $this->get(route('stock-transfers.pdf', $transfer))->assertOk();

        $this->post(route('stock-transfers.cancel', $transfer), ['reason' => 'Salah gudang'])->assertSessionHasNoErrors();
        $this->fabric->unsetRelation('warehouseStocks');
        $this->assertSame(200.0, $this->fabric->stockIn($this->main));
        $this->assertSame(0.0, $this->fabric->stockIn($this->finished));
    }

    public function test_stock_opname_posts_differences_with_journal_and_survives_rebuild(): void
    {
        app(LedgerRebuilder::class)->rebuild(); // jurnal saldo awal dari seeder

        $this->post(route('stock-opnames.store'), [
            'warehouse_id' => $this->main->id, 'date' => '2026-10-25', 'scope' => 'materials', 'only_in_stock' => 1,
        ])->assertSessionHasNoErrors();

        $opname = StockOpname::first();
        $this->assertStringStartsWith('OPN-202610-', $opname->number);
        $line = $opname->items()->where('stockable_type', 'material')->where('stockable_id', $this->fabric->id)->first();
        $this->assertSame(200.0, (float) $line->system_quantity);

        // Lembar hitung tanpa angka sistem; draft kedua untuk gudang & jenis yang sama ditolak
        $this->get(route('stock-opnames.pdf', $opname))->assertOk();
        $this->post(route('stock-opnames.store'), ['warehouse_id' => $this->main->id, 'date' => '2026-10-25', 'scope' => 'materials'])
            ->assertSessionHasErrors('warehouse_id');

        // Stok berubah setelah lembar dibuat: selisih dihitung terhadap stok saat posting
        DB::transaction(fn () => app(AutoJournal::class)->stockAdjustment(
            app(StockService::class)->move($this->fabric, -5, StockMovementType::Adjustment, notes: 'pakai', warehouse: $this->main)));

        $this->put(route('stock-opnames.update', $opname), ['counts' => [$line->id => 190], 'item_notes' => [$line->id => 'susut'], 'post' => 1])
            ->assertSessionHasNoErrors();

        $opname->refresh();
        $line->refresh();
        $this->assertSame(StockOpnameStatus::Posted, $opname->status);
        $this->assertSame(195.0, (float) $line->system_quantity);
        $this->assertSame(-5.0, (float) $line->difference);
        $this->assertSame(190.0, (float) $this->fabric->fresh()->stock);
        $this->assertSame(1, $opname->items->filter(fn ($i) => (float) $i->difference != 0)->count(), 'Barang yang tidak dihitung tidak disesuaikan');

        $movement = StockMovement::where('reference_type', 'stock_opname')->first();
        $this->assertNotNull($movement);
        $this->assertSame($this->main->id, $movement->warehouse_id);
        $entry = JournalEntry::where('source_type', 'stock_movement')->where('source_id', $movement->id)->first();
        $this->assertNotNull($entry);
        $this->assertEqualsWithDelta(5 * (float) $this->fabric->fresh()->average_cost, (float) $entry->lines()->sum('credit'), 0.01);

        $before = JournalEntry::count();
        app(LedgerRebuilder::class)->rebuild();
        $this->assertSame($before, JournalEntry::count());

        $this->get(route('stock-opnames.show', $opname))->assertOk()->assertSee('susut');
        $this->get(route('stock-opnames.index'))->assertOk()->assertSee($opname->number);
    }

    public function test_documents_record_their_warehouse(): void
    {
        $supplier = Supplier::where('code', 'SUP-001')->first();
        $this->post(route('purchase-orders.store'), [
            'supplier_id' => $supplier->id, 'order_date' => '2026-10-20',
            'items' => [['material_id' => $this->fabric->id, 'quantity' => 10, 'price' => 125000]],
        ])->assertSessionHasNoErrors();
        $po = PurchaseOrder::latest('id')->first();
        $this->post(route('purchase-orders.submit', $po))->assertSessionHasNoErrors();
        $this->post(route('purchase-orders.receive.store', $po), [
            'receipt_date' => '2026-10-21', 'warehouse_id' => $this->finished->id, 'items' => [$po->items()->first()->id => 10],
        ])->assertSessionHasNoErrors();

        $this->assertSame($this->finished->id, $po->receipts()->first()->warehouse_id);
        $this->fabric->unsetRelation('warehouseStocks');
        $this->assertSame(10.0, $this->fabric->stockIn($this->finished));

        // Pengiriman dari gudang yang tidak punya stok ditolak
        $variant = Product::where('code', 'TS-BSC')->with('variants')->first()->variants->first();
        DB::transaction(fn () => app(StockService::class)->move($variant, 20, StockMovementType::Adjustment, unitCost: 40000, warehouse: $this->finished));
        $this->post(route('sales-orders.store'), [
            'customer_id' => Customer::first()->id, 'order_date' => '2026-10-21',
            'items' => [['product_variant_id' => $variant->id, 'quantity' => 15, 'price' => 65000]],
        ]);
        $so = SalesOrder::latest('id')->first();
        $this->post(route('sales-orders.confirm', $so));
        $itemId = $so->items()->first()->id;

        $mainStock = $variant->fresh()->stockIn($this->main);
        if ($mainStock < 15) {
            $this->post(route('sales-orders.deliver.store', $so), ['delivery_date' => '2026-10-22', 'warehouse_id' => $this->main->id, 'items' => [$itemId => 15]])
                ->assertSessionHasErrors();
        }

        $this->post(route('sales-orders.deliver.store', $so), ['delivery_date' => '2026-10-22', 'warehouse_id' => $this->finished->id, 'items' => [$itemId => 15]])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->finished->id, $so->deliveries()->first()->warehouse_id);
        $this->assertSame(5.0, ProductVariant::find($variant->id)->stockIn($this->finished));

        $this->get(route('sales-orders.show', $so))->assertOk()->assertSee('Gudang Barang Jadi');
        $this->get(route('stock.materials', ['warehouse_id' => $this->finished->id]))->assertOk();
        $this->get(route('stock.products'))->assertOk()->assertSee('GJ 5');
        $this->get(route('stock.products.card', ['variant' => $variant, 'warehouse_id' => $this->finished->id]))->assertOk();
    }

    public function test_warehouse_master_and_permissions(): void
    {
        $this->get(route('warehouses.index'))->assertOk()->assertSee('Gudang Barang Jadi');
        $this->get(route('warehouses.show', $this->main))->assertOk();
        $this->get(route('warehouses.create'))->assertOk();
        $this->get(route('stock-transfers.create'))->assertOk();
        $this->get(route('stock-opnames.create'))->assertOk();

        // Gudang yang pernah dipakai tidak bisa dihapus; gudang default tidak bisa dinonaktifkan
        $this->delete(route('warehouses.destroy', $this->main))->assertSessionHas('error');
        $this->assertDatabaseHas('warehouses', ['id' => $this->main->id]);
        $this->delete(route('warehouses.destroy', $this->finished))->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('warehouses', ['id' => $this->finished->id]);

        $sales = User::factory()->create(['role' => Role::Sales]);
        $this->actingAs($sales);
        $this->get(route('stock-opnames.index'))->assertOk();
        $this->get(route('stock-opnames.create'))->assertForbidden();
        $this->get(route('stock-transfers.create'))->assertForbidden();
        $this->get(route('warehouses.create'))->assertForbidden();

        $gudang = User::factory()->create(['role' => Role::Gudang]);
        $this->actingAs($gudang);
        $this->get(route('stock-opnames.create'))->assertOk();
        $this->get(route('warehouses.create'))->assertOk();
    }
}
