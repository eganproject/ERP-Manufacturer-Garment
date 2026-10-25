<?php

namespace Tests\Feature;

use App\Enums\InvoiceStatus;
use App\Enums\ProductionCostType;
use App\Enums\ProductionOrderStatus;
use App\Enums\Role;
use App\Enums\StageStatus;
use App\Enums\StockMovementType;
use App\Enums\SubcontractStatus;
use App\Models\Bill;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\SubcontractOrder;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubcontractFlowTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private Supplier $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create());
        $this->product = Product::where('code', 'TS-BSC')->with('variants')->first();
        $this->vendor = Supplier::where('code', 'MKL-001')->first();
    }

    private function createSpk(int $perSize = 10): ProductionOrder
    {
        $this->post(route('production-orders.store'), [
            'product_id' => $this->product->id,
            'start_date' => '2026-10-25',
            'items' => $this->product->variants->take(2)->mapWithKeys(fn ($v) => [$v->id => $perSize])->all(),
        ])->assertRedirect();

        return ProductionOrder::latest('id')->first();
    }

    private function createOrder(ProductionOrder $spk, array $stages, ?array $materials = null): SubcontractOrder
    {
        $spk->load('materials');

        $this->post(route('subcontract-orders.store'), [
            'production_order_id' => $spk->id,
            'supplier_id' => $this->vendor->id,
            'order_date' => '2026-10-25',
            'due_date' => '2026-11-05',
            'stages' => $stages,
            'quantity' => (float) $spk->quantity,
            'rate' => 5000,
            'materials' => $materials ?? $spk->materials->pluck('required_quantity', 'material_id')->map(fn ($q) => (float) $q)->all(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        return SubcontractOrder::latest('id')->first();
    }

    public function test_full_cmt_flow_from_material_dispatch_to_vendor_bill(): void
    {
        $spk = $this->createSpk();
        $this->get(route('subcontract-orders.create', ['production_order_id' => $spk->id]))->assertOk()->assertSee('CMT Bu Ani');

        // Full maklon: semua tahap dikerjakan vendor
        $order = $this->createOrder($spk, ['cutting', 'sewing', 'finishing', 'qc', 'packing']);
        $this->assertSame('MKL-202610-0001', $order->number);
        $this->assertSame(5, $spk->stages()->whereNotNull('subcontract_order_id')->count());

        $fabric = Material::where('code', 'KN-CMB30-HTM')->first();
        $fabricBefore = (float) $fabric->stock;
        $required = (float) $spk->materials()->where('material_id', $fabric->id)->value('required_quantity');

        // SPK dimulai: bahan untuk vendor tidak dikeluarkan langsung
        $this->post(route('production-orders.start', $spk))->assertSessionHasNoErrors();
        $this->assertSame(ProductionOrderStatus::InProgress, $spk->fresh()->status);
        $this->assertEquals($fabricBefore, (float) $fabric->fresh()->stock);

        // Tahap maklon tidak bisa diubah manual
        $stage = $spk->stages()->where('stage', 'cutting')->first();
        $this->put(route('production-orders.stages.update', [$spk, $stage]), ['status' => 'done', 'output_quantity' => 20])->assertSessionHasErrors('status');

        // Kirim bahan ke vendor
        $this->get(route('subcontract-orders.transfer', [$order, 'dispatch']))->assertOk();
        $lines = $order->materials->mapWithKeys(fn ($m) => [$m->id => (float) $m->planned_quantity])->all();
        $this->post(route('subcontract-orders.transfer.store', [$order, 'dispatch']), ['date' => '2026-10-25', 'driver_name' => 'Joko', 'items' => $lines])
            ->assertSessionHasNoErrors();

        $this->assertEquals(round($fabricBefore - $required, 2), (float) $fabric->fresh()->stock);
        $fabricLine = $order->materials()->where('material_id', $fabric->id)->first();
        $this->assertEquals($required, (float) $fabricLine->sent_quantity);
        $this->assertEquals($required, (float) $fabricLine->balance());
        $this->assertEquals($required, (float) $spk->materials()->where('material_id', $fabric->id)->value('issued_quantity'));
        $this->assertSame(1, $fabric->stockMovements()->where('type', StockMovementType::SubcontractIssue)->count());

        // Terima hasil sebagian: 15 bagus, 1 reject
        $this->get(route('subcontract-orders.receive', $order))->assertOk();
        $this->post(route('subcontract-orders.receive.store', $order), ['date' => '2026-10-28', 'good_quantity' => 15, 'reject_quantity' => 1, 'vendor_reference' => 'SJ-ANI-01'])
            ->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertEquals(4, $order->outstandingQuantity());
        $this->assertEquals(75000, (float) $order->service_cost);
        $cost = $spk->costs()->where('type', ProductionCostType::Cmt)->first();
        $this->assertEquals(75000, (float) $cost->amount);
        $this->assertSame(StageStatus::InProgress, $spk->stages()->where('stage', 'sewing')->first()->status);

        // Biaya jasa maklon tidak bisa dihapus manual
        $this->delete(route('production-orders.costs.destroy', [$spk, $cost]))->assertSessionHasErrors('status');

        // Tidak boleh melebihi sisa
        $this->post(route('subcontract-orders.receive.store', $order), ['date' => '2026-10-29', 'good_quantity' => 5])->assertSessionHasErrors('good_quantity');

        $this->post(route('subcontract-orders.receive.store', $order), ['date' => '2026-10-29', 'good_quantity' => 4])->assertSessionHasNoErrors();
        $this->assertTrue($spk->stages()->get()->every(fn ($s) => $s->status === StageStatus::Done));
        $this->assertEquals(1, (float) $spk->stages()->where('stage', 'packing')->value('reject_quantity'));

        // SPK belum bisa ditutup selama order maklon berjalan
        $finished = $spk->items->mapWithKeys(fn ($i) => [$i->id => (float) $i->quantity])->all();
        $this->post(route('production-orders.complete', $spk), ['finished' => $finished])->assertSessionHasErrors('status');

        // Retur sisa kain 0,3 m dari vendor
        $this->post(route('subcontract-orders.transfer.store', [$order, 'return']), ['date' => '2026-10-29', 'items' => [$fabricLine->id => 0.3]])
            ->assertSessionHasNoErrors();
        $this->assertEquals(round($fabricBefore - $required + 0.3, 2), (float) $fabric->fresh()->stock);
        $this->assertEquals(round($required - 0.3, 2), (float) $spk->materials()->where('material_id', $fabric->id)->value('issued_quantity'));

        // Retur melebihi saldo ditolak
        $this->post(route('subcontract-orders.transfer.store', [$order, 'return']), ['date' => '2026-10-29', 'items' => [$fabricLine->id => 999]])
            ->assertSessionHasErrors();

        // Halaman, laporan & dokumen
        $this->get(route('subcontract-orders.show', $order))->assertOk()->assertSee('KBM-202610-0001')->assertSee('TMK-202610-0001');
        $this->get(route('subcontract-orders.index'))->assertOk()->assertSee($order->number);
        $this->get(route('reports.subcontract'))->assertOk()->assertSee('CMT Bu Ani');
        $this->get(route('reports.subcontract', ['export' => 'xlsx']))->assertOk();
        $this->get(route('reports.subcontract', ['view' => 'vendors', 'from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk()->assertSee('CMT Bu Ani');
        $this->get(route('production-orders.show', $spk))->assertOk()->assertSee($order->number);

        foreach ([
            route('subcontract-orders.pdf', $order),
            route('subcontract-transfers.pdf', $order->transfers()->first()),
            route('subcontract-transfers.pdf', $order->transfers()->latest('id')->first()),
            route('subcontract-receipts.pdf', $order->receipts()->first()),
        ] as $url) {
            $this->assertStringStartsWith('%PDF', $this->get($url)->assertOk()->getContent());
        }

        // Tutup order: sisa saldo dianggap terpakai
        $this->post(route('subcontract-orders.close', $order))->assertSessionHasNoErrors();
        $order->refresh();
        $this->assertSame(SubcontractStatus::Closed, $order->status);
        $this->assertEquals(round($required - 0.3, 2), (float) $order->materials()->where('material_id', $fabric->id)->value('used_quantity'));
        $this->assertFalse($order->fresh('materials')->hasMaterialAtVendor());

        // Jumlah jadi tidak boleh melebihi output tahap packing (19 bagus + 1 reject dari vendor)
        $this->post(route('production-orders.complete', $spk), ['finished' => $finished, 'reject_quantity' => 1])->assertSessionHasErrors('finished');

        // Selesaikan SPK: HPP = bahan bersih + upah/overhead standar + jasa maklon
        $finished[array_key_first($finished)] -= 1;
        $this->post(route('production-orders.complete', $spk), ['finished' => $finished, 'reject_quantity' => 1])->assertSessionHasNoErrors();
        $spk->refresh();
        $this->assertSame(ProductionOrderStatus::Completed, $spk->status);
        $materialCost = round($spk->materials()->get()->sum(fn ($l) => (float) $l->issued_quantity * (float) $l->unit_cost), 2);
        $this->assertEquals($materialCost, (float) $spk->material_cost);
        $this->assertEquals((float) $spk->costs()->sum('amount'), (float) $spk->conversion_cost);
        // Upah jahit standar tidak dibebankan karena jahit dimaklonkan: overhead 2.500 × 20 + jasa maklon 95.000
        $this->assertEquals(50000 + 95000, (float) $spk->conversion_cost);

        // Tagihan vendor dari penerimaan hasil
        $this->get(route('bills.create', ['subcontract_order_id' => $order->id]))->assertOk()->assertSee('TMK-202610-0002');
        $this->post(route('bills.store'), [
            'subcontract_order_id' => $order->id,
            'bill_date' => '2026-10-30',
            'supplier_invoice_number' => 'NOTA-ANI-7',
            'receipts' => $order->receipts()->pluck('id')->all(),
        ])->assertSessionHasNoErrors();

        $bill = Bill::latest('id')->first();
        $this->assertEquals(95000, (float) $bill->total);
        $this->assertSame($order->id, $bill->subcontract_order_id);
        $this->assertNull($bill->purchase_order_id);
        $this->assertEquals('2026-11-13', $bill->due_date->toDateString());
        $this->assertSame(0, $order->unbilledReceipts()->count());
        $this->get(route('bills.show', $bill))->assertOk()->assertSee($order->number)->assertSee('Jasa maklon');
        $this->get(route('bills.index'))->assertOk()->assertSee($order->number);
        $this->get(route('reports.purchases', ['group' => 'item', 'from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk()->assertSee('Jasa Maklon');
        $this->get(route('reports.payables-aging'))->assertOk();

        // Batal tagihan → penerimaan bisa ditagih ulang
        $this->post(route('bills.cancel', $bill))->assertSessionHasNoErrors();
        $this->assertSame(InvoiceStatus::Cancelled, $bill->fresh()->status);
        $this->assertSame(2, $order->unbilledReceipts()->count());
    }

    public function test_partial_stage_subcontract_keeps_internal_material_issue(): void
    {
        $spk = $this->createSpk();
        $spk->load('materials');
        $label = Material::where('code', 'LBL-WOVEN')->first();
        $fabric = Material::where('code', 'KN-CMB30-HTM')->first();
        $fabricBefore = (float) $fabric->stock;

        // Cutting internal, hanya jahit dimaklonkan: yang dikirim cuma label (potongan kain = WIP)
        $order = $this->createOrder($spk, ['sewing'], [$label->id => 20]);

        $this->post(route('production-orders.start', $spk))->assertSessionHasNoErrors();
        $fabricRequired = (float) $spk->materials->firstWhere('material_id', $fabric->id)->required_quantity;
        $this->assertEquals(round($fabricBefore - $fabricRequired, 2), (float) $fabric->fresh()->stock);
        $this->assertEquals(0, (float) $spk->materials()->where('material_id', $label->id)->value('issued_quantity'));

        // Penerimaan hasil sebelum cutting selesai ditolak
        $this->post(route('subcontract-orders.transfer.store', [$order, 'dispatch']), [
            'date' => '2026-10-25', 'items' => [$order->materials()->where('material_id', $label->id)->value('id') => 20],
        ])->assertSessionHasNoErrors();
        $this->post(route('subcontract-orders.receive.store', $order), ['date' => '2026-10-26', 'good_quantity' => 20])->assertSessionHasErrors('status');

        $cutting = $spk->stages()->where('stage', 'cutting')->first();
        $this->put(route('production-orders.stages.update', [$spk, $cutting]), ['status' => 'done', 'output_quantity' => 20])->assertSessionHasNoErrors();
        $this->post(route('subcontract-orders.receive.store', $order), ['date' => '2026-10-26', 'good_quantity' => 20])->assertSessionHasNoErrors();

        $this->assertSame(StageStatus::Done, $spk->stages()->where('stage', 'sewing')->first()->status);
        $this->assertSame(StageStatus::Pending, $spk->stages()->where('stage', 'finishing')->first()->status);
        $this->assertNull($spk->stages()->where('stage', 'cutting')->value('subcontract_order_id'));
    }

    public function test_guards_vendor_flag_cancel_and_permissions(): void
    {
        $spk = $this->createSpk();

        // Supplier biasa bukan vendor maklon
        $this->post(route('subcontract-orders.store'), [
            'production_order_id' => $spk->id, 'supplier_id' => Supplier::where('code', 'SUP-001')->first()->id,
            'order_date' => '2026-10-25', 'stages' => ['sewing'], 'quantity' => 20, 'rate' => 5000,
        ])->assertSessionHasErrors('supplier_id');

        $order = $this->createOrder($spk, ['sewing']);

        // Tahap yang sama tidak bisa dimaklonkan dua kali
        $this->post(route('subcontract-orders.store'), [
            'production_order_id' => $spk->id, 'supplier_id' => $this->vendor->id,
            'order_date' => '2026-10-25', 'stages' => ['sewing'], 'quantity' => 20, 'rate' => 5000,
        ])->assertSessionHasErrors('stages');

        // Kirim bahan sebelum SPK dimulai ditolak
        $this->post(route('subcontract-orders.transfer.store', [$order, 'dispatch']), [
            'date' => '2026-10-25', 'items' => [$order->materials()->first()->id => 1],
        ])->assertSessionHasErrors('status');

        // Peran Gudang boleh kirim bahan tapi tidak boleh membuat / menutup order maklon
        $this->actingAs(User::factory()->role(Role::Gudang)->create());
        $this->get(route('subcontract-orders.show', $order))->assertOk();
        $this->get(route('subcontract-orders.transfer', [$order, 'dispatch']))->assertOk();
        $this->post(route('subcontract-orders.cancel', $order))->assertForbidden();

        $this->actingAs(User::factory()->role(Role::Sales)->create());
        $this->get(route('subcontract-orders.transfer', [$order, 'dispatch']))->assertForbidden();

        // Batalkan order: tahap kembali internal
        $this->actingAs(User::factory()->role(Role::Produksi)->create());
        $this->post(route('subcontract-orders.cancel', $order))->assertSessionHasNoErrors();
        $this->assertSame(SubcontractStatus::Cancelled, $order->fresh()->status);
        $this->assertSame(0, $spk->stages()->whereNotNull('subcontract_order_id')->count());

        // Vendor maklon bisa ditandai dari master supplier
        $this->actingAs(User::factory()->create());
        $supplier = Supplier::where('code', 'SUP-002')->first();
        $this->put(route('suppliers.update', $supplier), [
            'code' => $supplier->code, 'name' => $supplier->name, 'payment_terms' => 30, 'is_active' => 1, 'is_subcontractor' => 1,
        ])->assertRedirect();
        $this->assertTrue($supplier->fresh()->is_subcontractor);
        $this->get(route('suppliers.index', ['type' => 'subcontractor']))->assertOk()->assertSee($supplier->name);
    }
}
