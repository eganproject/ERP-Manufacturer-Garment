<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StockLocation;
use App\Enums\StockMovementType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\DefectHandling;
use App\Models\JournalLine;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Accounting\LedgerRebuilder;
use App\Services\Reports\StockValuationReport;
use App\Services\StockService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DefectStockTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    private SalesOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-25 10:00:00');
        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create());

        // Hanya varian uji yang punya stok, agar nilai persediaan mudah dicocokkan dengan buku besar
        ProductVariant::query()->update(['stock' => 0, 'average_cost' => 0]);
        DB::table('stock_movements')->delete();
        $this->variant = Product::where('code', 'TS-BSC')->with('variants')->first()->variants->first();
        DB::transaction(fn () => app(StockService::class)->move($this->variant, 100, StockMovementType::Adjustment, unitCost: 45000));
        app(LedgerRebuilder::class)->rebuild();

        $this->post(route('sales-orders.store'), [
            'customer_id' => Customer::first()->id, 'order_date' => '2026-10-01',
            'items' => [['product_variant_id' => $this->variant->id, 'quantity' => 50, 'price' => 65000]],
        ]);
        $this->order = SalesOrder::first();
        $this->post(route('sales-orders.confirm', $this->order));
        $this->post(route('sales-orders.deliver.store', $this->order), ['delivery_date' => '2026-10-02', 'items' => [$this->order->items()->first()->id => 30]])
            ->assertSessionHasNoErrors();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function balance(string $code): float
    {
        $id = Account::where('code', $code)->value('id');

        return round((float) JournalLine::where('account_id', $id)->sum(DB::raw('debit - credit')), 2);
    }

    private function returnGoods(float $qty, float $defect)
    {
        $itemId = $this->order->items()->first()->id;

        return $this->post(route('sales-returns.store'), [
            'order_id' => $this->order->id, 'return_date' => '2026-10-05', 'reason' => 'Jahitan lepas',
            'items' => [$itemId => $qty], 'defects' => [$itemId => $defect],
        ]);
    }

    private function handle(string $type, float $qty, array $extra = [])
    {
        return $this->post(route('defects.store'), [
            'type' => $type, 'date' => '2026-10-10',
            'lines' => [['product_variant_id' => $this->variant->id, 'quantity' => $qty]],
        ] + $extra);
    }

    private function assertLedgerMatchesStock(): void
    {
        $inventory = $this->balance('1303') + $this->balance('1305');
        $this->assertEqualsWithDelta(app(StockValuationReport::class)->grandTotal()['products'], $inventory, 0.05);
        $this->assertEqualsWithDelta((float) $this->variant->fresh()->defect_stock * (float) $this->variant->fresh()->defect_cost, $this->balance('1305'), 0.05);
    }

    public function test_defective_sales_return_goes_to_defect_stock(): void
    {
        $this->returnGoods(10, 12)->assertSessionHasErrors('defects.'.$this->order->items()->first()->id);

        $this->returnGoods(10, 4)->assertSessionHasNoErrors();
        $variant = $this->variant->fresh();
        $this->assertSame(76.0, (float) $variant->stock); // 100 − 30 + 6 layak jual
        $this->assertSame(4.0, (float) $variant->defect_stock);
        $this->assertSame(45000.0, (float) $variant->defect_cost);

        $line = SalesReturn::first()->items->first();
        $this->assertSame(4.0, (float) $line->defect_quantity);
        $this->assertSame(180000.0, $this->balance('1305'));
        $this->assertSame(-450000.0 + 30 * 45000, $this->balance('5101')); // HPP kirim − retur
        $this->assertLedgerMatchesStock();

        $movement = StockMovement::where('location', StockLocation::Defect)->first();
        $this->assertSame(4.0, (float) $movement->balance);

        // Stok cacat tidak bisa dikirim: pengiriman memakai stok jual saja
        $this->post(route('sales-orders.deliver.store', $this->order), ['delivery_date' => '2026-10-06', 'items' => [$this->order->items()->first()->id => 20]])
            ->assertSessionHasNoErrors();
        $this->assertSame(4.0, (float) $variant->fresh()->defect_stock);

        $this->get(route('sales-returns.show', SalesReturn::first()))->assertOk()->assertSee('4 cacat');
        $this->get(route('stock.products.card', $variant))->assertOk()->assertSee('Stok Cacat');
        $this->get(route('stock.products'))->assertOk();
        $this->get(route('reports.stock-valuation', ['type' => 'products']))->assertOk()->assertSee('cacat');

        // Retur dibatalkan: barang cacat keluar lagi dari stok cacat
        $this->post(route('sales-returns.cancel', SalesReturn::first()), ['reason' => 'salah input'])->assertSessionHasNoErrors();
        $this->assertSame(0.0, (float) $variant->fresh()->defect_stock);
        $this->assertSame(0.0, $this->balance('1305'));
    }

    public function test_rework_restock_scrap_and_quarantine(): void
    {
        $this->returnGoods(10, 6)->assertSessionHasNoErrors();
        $kas = Account::where('code', '1101')->value('id');

        // Perbaiki 2 pcs, biaya jahit ulang 10.000 dari kas → HPP 50.000/pcs masuk stok jual
        $this->handle('rework', 2, ['extra_cost' => 10000])->assertSessionHasErrors('cash_account_id');
        $this->handle('rework', 2, ['extra_cost' => 10000, 'cash_account_id' => $kas, 'notes' => 'Jahit ulang'])->assertSessionHasNoErrors();
        $rework = DefectHandling::latest('id')->first();
        $this->assertStringStartsWith('BC-202610-', $rework->number);
        $this->assertSame(90000.0, (float) $rework->total_cost);
        $this->assertSame(4.0, (float) $this->variant->fresh()->defect_stock);
        $this->assertSame(-10000.0, $this->balance('1101'));
        $this->assertSame(180000.0, $this->balance('1305'));
        $mainIn = StockMovement::where('reference_type', 'defect_handling')->where('location', StockLocation::Main)->first();
        $this->assertSame(50000.0, (float) $mainIn->unit_cost);

        // Musnahkan 1 → beban barang cacat
        $this->handle('scrap', 1)->assertSessionHasNoErrors();
        $this->assertSame(45000.0, $this->balance('5105'));

        // Kembalikan 1 ke stok jual tanpa biaya
        $this->handle('restock', 1)->assertSessionHasNoErrors();
        $this->assertSame(2.0, (float) $this->variant->fresh()->defect_stock);

        // Melebihi stok cacat ditolak
        $this->handle('scrap', 5)->assertSessionHasErrors('stock');

        // Temuan gudang: pindahkan 3 pcs stok jual ke stok cacat
        $stockBefore = (float) $this->variant->fresh()->stock;
        $this->handle('quarantine', 3)->assertSessionHasNoErrors();
        $this->assertSame($stockBefore - 3, (float) $this->variant->fresh()->stock);
        $this->assertSame(5.0, (float) $this->variant->fresh()->defect_stock);
        $this->assertLedgerMatchesStock();

        // Batalkan pemusnahan → barang kembali ke stok cacat, beban dibalik
        $scrap = DefectHandling::where('type', 'scrap')->first();
        $this->post(route('defects.cancel', $scrap), ['reason' => 'Ternyata bisa diperbaiki'])->assertSessionHasNoErrors();
        $this->assertSame(6.0, (float) $this->variant->fresh()->defect_stock);
        $this->assertSame(0.0, $this->balance('5105'));
        $this->assertLedgerMatchesStock();

        // Bangun ulang jurnal menghasilkan saldo yang sama
        $codes = ['1101', '1303', '1305', '5101', '5105'];
        $before = collect($codes)->mapWithKeys(fn ($c) => [$c => $this->balance($c)])->all();
        app(LedgerRebuilder::class)->rebuild();
        $this->assertEquals($before, collect($codes)->mapWithKeys(fn ($c) => [$c => $this->balance($c)])->all());

        foreach ([route('defects.index'), route('defects.create'), route('defects.create', ['type' => 'scrap', 'variant_id' => $this->variant->id]),
            route('defects.create', ['type' => 'quarantine']), route('defects.show', $rework), route('sales-returns.create', ['order_id' => $this->order->id])] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get(route('defects.index'))->assertSee($this->variant->sku);
        $this->get(route('sales-returns.create', ['order_id' => $this->order->id]))->assertSee('Di antaranya Cacat');
    }

    public function test_access_and_work_queue(): void
    {
        $this->returnGoods(5, 5)->assertSessionHasNoErrors();

        $this->actingAs(User::factory()->role(Role::Sales)->create());
        $this->get(route('defects.index'))->assertOk();
        $this->handle('scrap', 1)->assertForbidden();
        $this->get(route('dashboard'))->assertDontSee('Barang cacat menunggu diproses');

        $this->actingAs(User::factory()->role(Role::Gudang)->create());
        $this->get(route('dashboard'))->assertOk()->assertSee('Barang cacat menunggu diproses');
        $this->handle('scrap', 1)->assertSessionHasNoErrors();
    }
}
