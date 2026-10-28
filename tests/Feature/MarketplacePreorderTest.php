<?php

namespace Tests\Feature;

use App\Enums\MarketplacePlatform;
use App\Enums\ProductionOrderStatus;
use App\Enums\StockMovementType;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceStore;
use App\Models\ProductionOrder;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Marketplace\MarketplaceFulfillment;
use App\Services\Marketplace\MarketplaceImporter;
use App\Services\MrpService;
use App\Services\ProductionPlanner;
use App\Services\Reports\WorkQueue;
use App\Services\StockService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Pesanan marketplace PO vs ready stock → alokasi stok → rencana produksi (SPK) → siap dikirim. */
class MarketplacePreorderTest extends TestCase
{
    use RefreshDatabase;

    private MarketplaceStore $shopee;

    private Warehouse $online;

    private ProductVariant $m;

    private ProductVariant $l;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-25 10:00:00');
        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create());

        $this->online = Warehouse::create(['code' => 'OL', 'name' => 'Gudang Online', 'is_active' => true]);
        $this->shopee = MarketplaceStore::create(['platform' => MarketplacePlatform::Shopee, 'name' => 'Garmen Official', 'warehouse_id' => $this->online->id, 'tax_rate' => 0, 'handling_days' => 2]);
        $this->m = ProductVariant::where('sku', 'TS-BSC-HTM-M')->first();
        $this->l = ProductVariant::where('sku', 'TS-BSC-HTM-L')->first();

        DB::transaction(fn () => app(StockService::class)->move($this->m, 50, StockMovementType::Adjustment, notes: 'awal', unitCost: 40000, warehouse: $this->online));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function csv(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'po').'.csv';
        $fh = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($fh, $row, ';', '"', '');
        }
        fclose($fh);

        return new UploadedFile($path, 'Order.toship.csv', 'text/csv', null, true);
    }

    /** @param  list<array{0: string, 1: string, 2: int|string, 3?: string}>  $orders  [no, sku, qty, batas kirim] */
    private function import(array $orders, bool $withShipBy = true): array
    {
        $header = ['No. Pesanan', 'Status Pesanan', 'Waktu Pesanan Dibuat', 'Waktu Pembayaran Dilakukan', 'Nomor Referensi SKU', 'Nama Produk', 'Jumlah', 'Harga Setelah Diskon'];
        if ($withShipBy) {
            $header[] = 'Pesanan Harus Dikirimkan Sebelum (Menghindari keterlambatan)';
        }
        $rows = [$header];
        foreach ($orders as $o) {
            $row = [$o[0], 'Perlu Dikirim', '24/10/2026 09:00', '24/10/2026 09:05', $o[1], 'Kaos Basic', (string) $o[2], '99.000'];
            if ($withShipBy) {
                $row[] = $o[3] ?? '';
            }
            $rows[] = $row;
        }

        return app(MarketplaceImporter::class)->importFile($this->shopee, $this->csv($rows)->getRealPath(), 'order.csv');
    }

    private function order(string $number): MarketplaceOrder
    {
        return MarketplaceOrder::where('order_number', $number)->firstOrFail();
    }

    public function test_ship_by_from_file_marks_preorder_and_ready_stock_uses_handling_days(): void
    {
        $this->import([['PO-1', 'TS-BSC-HTM-M', 1, '23/11/2026 23:59'], ['RS-1', 'TS-BSC-HTM-M', 1, '']]);

        $po = $this->order('PO-1');
        $this->assertTrue($po->is_preorder);
        $this->assertTrue($po->ship_by_fixed);
        $this->assertSame('2026-11-23', $po->ship_by->toDateString());

        // Tanpa batas kirim di file: tgl bayar + hari proses toko (2 hari)
        $ready = $this->order('RS-1');
        $this->assertFalse($ready->is_preorder);
        $this->assertSame('2026-10-26', $ready->ship_by->toDateString());
    }

    public function test_preorder_days_on_sku_mapping_and_bulk_update_reschedule_open_orders(): void
    {
        MarketplaceProduct::create(['marketplace_store_id' => $this->shopee->id, 'marketplace_sku' => 'TS-BSC-HTM-L', 'product_variant_id' => $this->l->id, 'units_per_item' => 1, 'preorder_days' => 30]);
        $this->import([['A1', 'TS-BSC-HTM-L', 2], ['A2', 'TS-BSC-HTM-M', 1]], false);

        $this->assertTrue($this->order('A1')->is_preorder);
        $this->assertSame('2026-11-23', $this->order('A1')->ship_by->toDateString());
        $this->assertFalse($this->order('A2')->is_preorder);

        // SKU M dijadikan PO 14 hari lewat aksi massal → pesanan terbuka dihitung ulang
        $mappingM = MarketplaceProduct::where('marketplace_sku', 'TS-BSC-HTM-M')->firstOrFail();
        $this->post(route('marketplace-mappings.preorder'), ['mappings' => [$mappingM->id], 'preorder_days' => 14])->assertSessionHas('success');
        $this->assertTrue($this->order('A2')->is_preorder);
        $this->assertSame('2026-11-07', $this->order('A2')->ship_by->toDateString());

        // Ubah manual: batas kirim tetap, tidak dihitung ulang
        $this->post(route('marketplace-orders.schedule', $this->order('A2')), ['is_preorder' => '0', 'ship_by' => '2026-10-30'])->assertSessionHas('success');
        $this->assertFalse($this->order('A2')->is_preorder);
        $this->assertTrue($this->order('A2')->ship_by_fixed);
        $this->import([['A2', 'TS-BSC-HTM-M', 1]], false);
        $this->assertSame('2026-10-30', $this->order('A2')->ship_by->toDateString());
    }

    public function test_allocation_by_ship_by_and_production_plan_creates_spk_then_orders_become_ready(): void
    {
        // Stok M = 50. B (batas paling awal) 30 → siap; A 40 → menunggu; C 15 → siap (sisa 20 cukup)
        $this->import([
            ['2610A', 'TS-BSC-HTM-M', 40, '20/11/2026'],
            ['2610B', 'TS-BSC-HTM-M', 30, '05/11/2026'],
            ['2610C', 'TS-BSC-HTM-M', 15, '25/11/2026'],
            ['2610D', 'TS-BSC-HTM-L', 10, '10/11/2026'],
        ]);

        $fulfillment = app(MarketplaceFulfillment::class);
        $allocation = $fulfillment->allocate();
        $this->assertSame(MarketplaceFulfillment::READY, $allocation[$this->order('2610B')->id]['state']);
        $this->assertSame(MarketplaceFulfillment::WAITING, $allocation[$this->order('2610A')->id]['state']);
        $this->assertEquals([$this->m->id => 20.0], $allocation[$this->order('2610A')->id]['short']);
        $this->assertSame(MarketplaceFulfillment::READY, $allocation[$this->order('2610C')->id]['state']);
        $this->assertSame(MarketplaceFulfillment::WAITING, $allocation[$this->order('2610D')->id]['state']);

        // Rencana produksi: M = 85 dipesan − 50 stok = 35; L = 10; batas kirim terdekat yang belum siap = D (10 Nov)
        $rows = app(ProductionPlanner::class)->rows()->keyBy(fn ($r) => $r['variant']->sku);
        $this->assertEquals(35, $rows['TS-BSC-HTM-M']['net']);
        $this->assertEquals(85, $rows['TS-BSC-HTM-M']['preorder']);
        $this->assertEquals(10, $rows['TS-BSC-HTM-L']['net']);
        $this->assertSame('2026-11-20', $rows['TS-BSC-HTM-M']['ship_by']->toDateString());

        // MRP ikut menghitung pesanan marketplace
        $mrp = app(MrpService::class)->calculate();
        $this->assertEquals(35, $mrp['products']->firstWhere(fn ($r) => $r['variant']->id === $this->m->id)['net']);
        $this->assertSame(4, $mrp['marketplace_order_count']);
        $this->assertEquals(0, app(MrpService::class)->calculate(null, true, false)['products']->count());

        $this->get(route('production-plan.index'))->assertOk()->assertSee('Kaos Basic')->assertSee('Buat SPK Draft');
        $productId = $this->m->product_id;
        $this->post(route('production-plan.store'), [
            'selected' => [$productId],
            'products' => [$productId => [$this->m->id => 35, $this->l->id => 10]],
            'due' => [$productId => '2026-11-09'],
            'warehouse_id' => $this->online->id,
        ])->assertRedirect()->assertSessionHas('success');

        $spk = ProductionOrder::with('items')->latest('id')->firstOrFail();
        $this->assertSame(ProductionOrderStatus::Draft, $spk->status);
        $this->assertEquals(45, (float) $spk->quantity);
        $this->assertSame('2026-11-09', $spk->due_date->toDateString());
        $this->assertSame($this->online->id, $spk->warehouse_id);
        $this->assertTrue($spk->materials()->exists());

        // SPK berjalan mengurangi kekurangan
        $this->assertEquals(0, app(ProductionPlanner::class)->rows()->sum('net'));

        // Hasil produksi masuk gudang toko → pesanan otomatis siap dikirim
        DB::transaction(function () {
            app(StockService::class)->move($this->m, 35, StockMovementType::Adjustment, notes: 'hasil SPK', unitCost: 40000, warehouse: $this->online);
            app(StockService::class)->move($this->l, 10, StockMovementType::Adjustment, notes: 'hasil SPK', unitCost: 40000, warehouse: $this->online);
        });
        $fulfillment->forget();
        $this->assertCount(4, $fulfillment->idsInState(MarketplaceFulfillment::READY));
    }

    public function test_pages_picking_list_and_work_queue(): void
    {
        Carbon::setTestNow('2026-11-03 10:00:00');
        $this->import([['2610B', 'TS-BSC-HTM-M', 30, '05/11/2026'], ['2610A', 'TS-BSC-HTM-M', 40, '05/11/2026']]);

        $this->get(route('marketplace-orders.index', ['status' => 'waiting']))->assertOk()->assertSee('Menunggu produksi')->assertDontSee('>2610B<', false);
        $this->get(route('marketplace-orders.index', ['status' => 'ready']))->assertOk()->assertSee('Siap dikirim');
        $this->get(route('marketplace-orders.index', ['status' => 'preorder']))->assertOk();
        $this->get(route('marketplace-orders.show', $this->order('2610A')))->assertOk()->assertSee('Menunggu produksi')->assertSee('Rencana Produksi');
        $this->get(route('marketplace-orders.show', $this->order('2610B')))->assertOk()->assertSee('Daftar Ambil Barang');
        $this->get(route('marketplace-orders.picking'))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('marketplace-mappings.index', ['type' => 'ready']))->assertOk()->assertSee('Ready stock');
        $this->get(route('mrp.index'))->assertOk()->assertSee('Pesanan marketplace');

        $labels = app(WorkQueue::class)->for(auth()->user())->pluck('count', 'label');
        $this->assertSame(1, $labels['Pesanan marketplace siap dikirim']);
        $this->assertSame(1, $labels['Pesanan marketplace menunggu produksi']);
        $this->assertSame(1, $labels['Pesanan PO mendekati batas kirim, barang belum siap']);
        $this->get(route('dashboard'))->assertOk();
    }
}
