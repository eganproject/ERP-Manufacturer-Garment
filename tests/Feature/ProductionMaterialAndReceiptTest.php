<?php

namespace Tests\Feature;

use App\Enums\ProductionOrderStatus;
use App\Enums\StageStatus;
use App\Models\Account;
use App\Models\JournalLine;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductionMaterialTransfer;
use App\Models\ProductionOrder;
use App\Models\ProductionReceipt;
use App\Models\User;
use App\Services\Accounting\LedgerRebuilder;
use App\Services\ProductionService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionMaterialAndReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    private function startedSpk(int $quantity = 100): ProductionOrder
    {
        $product = Product::where('code', 'TS-BSC')->with('variants')->first();

        $this->post(route('production-orders.store'), [
            'product_id' => $product->id,
            'start_date' => '2026-10-24',
            'items' => [$product->variants[0]->id => $quantity / 2, $product->variants[1]->id => $quantity / 2],
        ])->assertSessionHasNoErrors();
        $spk = ProductionOrder::latest('id')->first();
        $this->post(route('production-orders.start', $spk))->assertSessionHasNoErrors();

        return $spk->fresh();
    }

    private function balance(string $code): float
    {
        $id = Account::where('code', $code)->value('id');

        return round((float) JournalLine::where('account_id', $id)->sum('debit') - (float) JournalLine::where('account_id', $id)->sum('credit'), 2);
    }

    public function test_additional_material_and_leftover_return_move_stock_and_wip(): void
    {
        $spk = $this->startedSpk();
        $fabric = Material::where('code', 'KN-CMB30-HTM')->first();
        $line = $spk->materials()->where('material_id', $fabric->id)->first();
        $issued = (float) $line->issued_quantity;
        $stock = (float) $fabric->fresh()->stock;
        $wip = $this->balance('1302');

        $this->get(route('production-orders.materials', [$spk, 'issue']))->assertOk()->assertSee('Bahan di luar BOM');

        // Kain kurang 3 kg + bahan di luar BOM
        $extra = Material::whereNotIn('id', $spk->materials()->pluck('material_id'))->first();
        $this->post(route('production-orders.materials.store', [$spk, 'issue']), [
            'date' => '2026-10-25', 'notes' => 'Salah potong',
            'items' => [$fabric->id => 3], 'extra_material_id' => $extra->id, 'extra_quantity' => 2,
        ])->assertSessionHasNoErrors();

        $transfer = ProductionMaterialTransfer::first();
        $this->assertStringStartsWith('BBT-', $transfer->number);
        $this->assertEquals($issued + 3, (float) $line->fresh()->issued_quantity);
        $this->assertEquals($stock - 3, (float) $fabric->fresh()->stock);
        $this->assertEquals(2, (float) $spk->materials()->where('material_id', $extra->id)->value('issued_quantity'));
        $this->assertEqualsWithDelta($wip + $transfer->totalValue(), $this->balance('1302'), 0.01);

        // Retur sisa kain 5 kg ke gudang, tidak boleh melebihi yang dikeluarkan
        $this->post(route('production-orders.materials.store', [$spk, 'return']), [
            'date' => '2026-10-26', 'items' => [$fabric->id => $issued + 100],
        ])->assertSessionHasErrors("items.{$fabric->id}");

        $this->get(route('production-orders.materials', [$spk, 'return']))->assertOk();
        $this->post(route('production-orders.materials.store', [$spk, 'return']), [
            'date' => '2026-10-26', 'items' => [$fabric->id => 5],
        ])->assertSessionHasNoErrors();

        $return = ProductionMaterialTransfer::where('type', 'return')->first();
        $this->assertStringStartsWith('RSB-', $return->number);
        $line->refresh();
        $this->assertEquals($issued - 2, (float) $line->issued_quantity);
        $this->assertEquals(5, (float) $line->returned_quantity);
        $this->assertEquals($stock + 2, (float) $fabric->fresh()->stock);
        $this->assertEqualsWithDelta($wip + $transfer->totalValue() - $return->load('items')->totalValue(), $this->balance('1302'), 0.01);
        $this->assertEquals('production_return', $fabric->stockMovements()->latest('id')->value('type')->value);

        $this->get(route('production-material-transfers.pdf', $return))->assertOk();
        $this->get(route('production-orders.show', $spk))->assertOk()->assertSee($transfer->number)->assertSee($return->number);
    }

    public function test_partial_receipts_then_completion_absorb_actual_cost_and_clear_wip(): void
    {
        $spk = $this->startedSpk();
        $items = $spk->items()->with('variant')->get();
        [$a, $b] = [$items[0], $items[1]];

        $estimate = app(ProductionService::class)->estimatedUnitCost($spk);
        $this->assertGreaterThan(0, $estimate);

        // Setoran pertama: 30 bagus ukuran A, 2 grade B
        $this->get(route('production-orders.receipts.create', $spk))->assertOk()->assertSee('HPP sementara');
        $this->post(route('production-orders.receipts.store', $spk), [
            'date' => '2026-10-26',
            'items' => [$a->id => ['good' => 30, 'defect' => 2]],
        ])->assertSessionHasNoErrors();

        $first = ProductionReceipt::first();
        $this->assertStringStartsWith('HPJ-', $first->number);
        $this->assertEquals($estimate, (float) $first->unit_cost);
        $this->assertEquals(30, (float) $a->variant->fresh()->stock);
        $this->assertEquals(2, (float) $a->variant->fresh()->defect_stock);
        $this->assertEqualsWithDelta(2 * $estimate, $this->balance('1305'), 0.01);
        $this->assertSame(ProductionOrderStatus::InProgress, $spk->fresh()->status);

        // Tidak boleh melebihi sisa rencana per ukuran
        $this->post(route('production-orders.receipts.store', $spk), [
            'date' => '2026-10-26', 'items' => [$a->id => ['good' => 19]],
        ])->assertSessionHasErrors("items.{$a->id}");

        // Biaya tambahan sesudah setoran pertama diserap setoran penutup
        $this->post(route('production-orders.costs.store', $spk), [
            'type' => 'other', 'description' => 'Lembur', 'amount' => 200000, 'date' => '2026-10-27',
        ])->assertSessionHasNoErrors();

        foreach ($spk->stages()->get() as $stage) {
            $this->put(route('production-orders.stages.update', [$spk, $stage]), ['status' => StageStatus::Done->value, 'output_quantity' => 96])->assertSessionHasNoErrors();
        }

        // Sisa: A 18 bagus, B 48 bagus + 1 grade B (1 pcs dibuang)
        $this->post(route('production-orders.complete', $spk), [
            'finished' => [$a->id => 18, $b->id => 48],
            'defects' => [$b->id => 1],
            'reject_quantity' => 4,
        ])->assertSessionHasNoErrors();

        $spk->refresh();
        $final = ProductionReceipt::where('is_final', true)->first();
        $this->assertSame(ProductionOrderStatus::Completed, $spk->status);
        $this->assertEquals(96, (float) $spk->finished_quantity);
        $this->assertEquals(3, (float) $spk->defect_quantity);
        $this->assertEquals(4, (float) $spk->reject_quantity);
        $this->assertEquals(48, (float) $b->variant->fresh()->stock);
        $this->assertEquals(1, (float) $b->variant->fresh()->defect_stock);

        // Total nilai setoran ≈ biaya aktual SPK, WIP SPK bersih
        $this->assertEqualsWithDelta((float) $spk->total_cost, (float) $first->total_cost + (float) $final->total_cost, 1);
        $this->assertEqualsWithDelta(0, $this->balance('1302'), 0.001);
        $this->assertEqualsWithDelta((float) $first->defectValue() + (float) $final->defectValue(), $this->balance('1305'), 0.01);
        $this->assertEqualsWithDelta(round((float) $spk->total_cost / 99, 2), (float) $spk->unit_cost, 0.01);

        $this->get(route('production-receipts.pdf', $final))->assertOk();
        $this->get(route('production-orders.show', $spk))->assertOk()->assertSee($first->number)->assertSee('Setoran Penutup');

        // Bangun ulang jurnal menghasilkan saldo yang sama
        $before = collect(['1302', '1303', '1305', '5103'])->mapWithKeys(fn ($c) => [$c => $this->balance($c)]);
        app(LedgerRebuilder::class)->rebuild();
        foreach ($before as $code => $value) {
            $this->assertEqualsWithDelta($value, $this->balance($code), 0.01, "Saldo {$code} berubah setelah rebuild");
        }
    }

    public function test_material_and_receipt_actions_need_running_spk(): void
    {
        $product = Product::where('code', 'TS-BSC')->with('variants')->first();
        $this->post(route('production-orders.store'), [
            'product_id' => $product->id, 'start_date' => '2026-10-24', 'items' => [$product->variants[0]->id => 10],
        ]);
        $spk = ProductionOrder::first();
        $fabric = Material::where('code', 'KN-CMB30-HTM')->first();

        $this->post(route('production-orders.materials.store', [$spk, 'issue']), ['date' => '2026-10-25', 'items' => [$fabric->id => 1]])
            ->assertSessionHasErrors('status');
        $this->post(route('production-orders.receipts.store', $spk), ['date' => '2026-10-25', 'items' => [$spk->items()->first()->id => ['good' => 1]]])
            ->assertSessionHasErrors('status');
        $this->get(route('production-orders.receipts.create', $spk))->assertRedirect();
    }
}
