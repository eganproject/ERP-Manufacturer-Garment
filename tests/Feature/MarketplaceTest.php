<?php

namespace Tests\Feature;

use App\Enums\MarketplaceOrderStatus;
use App\Enums\MarketplacePlatform;
use App\Enums\Role;
use App\Enums\StockMovementType;
use App\Models\JournalLine;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceProduct;
use App\Models\MarketplaceSettlement;
use App\Models\MarketplaceStore;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Accounting\LedgerRebuilder;
use App\Services\Marketplace\Connectors\ShopeeConnector;
use App\Services\Marketplace\Connectors\TikTokConnector;
use App\Services\Marketplace\MarketplaceImporter;
use App\Services\Marketplace\MarketplaceOrderService;
use App\Services\Marketplace\MarketplaceSettlementService;
use App\Services\Marketplace\MarketplaceSyncService;
use App\Services\Reports\MarketplaceReport;
use App\Services\StockService;
use App\Support\LedgerAccounts;
use App\Support\SpreadsheetReader;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MarketplaceTest extends TestCase
{
    use RefreshDatabase;

    private MarketplaceStore $shopee;

    private Warehouse $online;

    private ProductVariant $tshirtM;

    private ProductVariant $tshirtL;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-25 10:00:00');
        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create());

        $this->online = Warehouse::create(['code' => 'OL', 'name' => 'Gudang Online', 'is_active' => true]);
        $this->shopee = MarketplaceStore::create(['platform' => MarketplacePlatform::Shopee, 'name' => 'Garmen Official', 'warehouse_id' => $this->online->id, 'tax_rate' => 0]);
        $this->tshirtM = ProductVariant::where('sku', 'TS-BSC-HTM-M')->first();
        $this->tshirtL = ProductVariant::where('sku', 'TS-BSC-HTM-L')->first();

        DB::transaction(function () {
            app(StockService::class)->move($this->tshirtM, 50, StockMovementType::Adjustment, notes: 'awal', unitCost: 40000, warehouse: $this->online);
            app(StockService::class)->move($this->tshirtL, 20, StockMovementType::Adjustment, notes: 'awal', unitCost: 42000, warehouse: $this->online);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function balance(string $role, $reference = null): float
    {
        $id = app(LedgerAccounts::class)->id($role);

        return round((float) JournalLine::where('account_id', $id)
            ->when($reference, fn ($q) => $q->where('reference_type', $reference->getMorphClass())->where('reference_id', $reference->getKey()))
            ->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) AS b')->value('b'), 2);
    }

    private function csv(array $rows): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'mp').'.csv';
        $fh = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($fh, $row, ';', '"', '');
        }
        fclose($fh);

        return new UploadedFile($path, 'Order.all.20261025.csv', 'text/csv', null, true);
    }

    private function shopeeExport(string $status, array $extra = []): array
    {
        $header = ['No. Pesanan', 'Status Pesanan', 'Waktu Pesanan Dibuat', 'Waktu Pengiriman Diatur', 'Username (Pembeli)', 'Nama Penerima', 'Nomor Referensi SKU', 'Nama Produk', 'Nama Variasi', 'Harga Setelah Diskon', 'Jumlah', 'Total Harga Produk'];

        return [
            $header,
            ['2610250001ABC', $status, '20/10/2026 09:15', $extra['shipped'] ?? '21/10/2026 14:00', 'budi99', 'Budi', 'TS-BSC-HTM-M', 'Kaos Basic', 'Hitam,M', 'Rp 99.000', '2', '198.000'],
            ['2610250001ABC', $status, '20/10/2026 09:15', $extra['shipped'] ?? '21/10/2026 14:00', 'budi99', 'Budi', 'PAKET-L3', 'Kaos Basic isi 3', 'Hitam,L', '250.000', '1', '250.000'],
        ];
    }

    public function test_import_creates_order_auto_maps_sku_and_reserves_stock_until_mapped(): void
    {
        $summary = app(MarketplaceImporter::class)->importFile($this->shopee, $this->csv($this->shopeeExport('Perlu Dikirim'))->getRealPath(), 'order.csv');

        $this->assertSame(1, $summary['created']);
        $order = MarketplaceOrder::with('items')->first();
        $this->assertSame(MarketplaceOrderStatus::ToShip, $order->status);
        $this->assertSame(448000.0, (float) $order->total);
        $this->assertSame('2026-10-20', $order->order_date->toDateString());

        // SKU sama dengan SKU varian ERP → dipetakan otomatis; SKU paket belum
        $this->assertSame($this->tshirtM->id, $order->items->firstWhere('marketplace_sku', 'TS-BSC-HTM-M')->product_variant_id);
        $this->assertNull($order->items->firstWhere('marketplace_sku', 'PAKET-L3')->product_variant_id);
        $this->assertTrue(MarketplaceProduct::where('marketplace_sku', 'TS-BSC-HTM-M')->exists());

        // Impor ulang berstatus dikirim: gagal kirim karena SKU paket belum dipetakan, pesanan tetap Perlu Dikirim
        $summary = app(MarketplaceImporter::class)->importFile($this->shopee, $this->csv($this->shopeeExport('Sedang Dikirim'))->getRealPath(), 'order.csv');
        $this->assertSame(0, $summary['created']);
        $this->assertNotEmpty($summary['warnings']);
        $this->assertSame(MarketplaceOrderStatus::ToShip, $order->fresh()->status);
        $this->assertSame(1, MarketplaceOrder::count());

        // Petakan paket isi 3 lalu impor ulang → terkirim, stok keluar 2 + 3 pcs
        $mapping = MarketplaceProduct::create(['marketplace_store_id' => $this->shopee->id, 'marketplace_sku' => 'PAKET-L3', 'product_variant_id' => $this->tshirtL->id, 'units_per_item' => 3]);
        app(MarketplaceOrderService::class)->applyMapping($mapping);
        app(MarketplaceImporter::class)->importFile($this->shopee, $this->csv($this->shopeeExport('Sedang Dikirim'))->getRealPath(), 'order.csv');

        $order->refresh();
        $this->assertSame(MarketplaceOrderStatus::Shipped, $order->status);
        $this->assertSame('2026-10-21', $order->shipped_at->toDateString());
        $this->assertSame(48.0, $this->tshirtM->fresh()->stockIn($this->online));
        $this->assertSame(17.0, $this->tshirtL->fresh()->stockIn($this->online));
        $this->assertSame(2 * 40000 + 3 * 42000.0, (float) $order->cost);

        // Jurnal: piutang marketplace per toko, penjualan marketplace, HPP
        $this->assertSame(448000.0, $this->balance('marketplace_receivable', $this->shopee));
        $this->assertSame(-448000.0, $this->balance('marketplace_sales'));
        $this->assertSame(206000.0, $this->balance('cogs'));
    }

    public function test_ppn_inclusive_store_splits_dpp_and_return_reverses_sale_and_restocks_defect(): void
    {
        $this->shopee->update(['tax_rate' => 11]);
        MarketplaceProduct::create(['marketplace_store_id' => $this->shopee->id, 'marketplace_sku' => 'PAKET-L3', 'product_variant_id' => $this->tshirtL->id, 'units_per_item' => 3]);
        app(MarketplaceImporter::class)->importFile($this->shopee, $this->csv($this->shopeeExport('Selesai'))->getRealPath(), 'order.csv');

        $order = MarketplaceOrder::with('items')->first();
        $this->assertSame(MarketplaceOrderStatus::Completed, $order->status);
        $this->assertSame(round(198000 / 1.11, 2) + round(250000 / 1.11, 2), (float) $order->dpp);
        $this->assertSame(-(float) $order->tax_amount, $this->balance('vat_out'));

        // Pembeli mengajukan pengembalian → retur diproses (stok belum kembali)
        app(MarketplaceImporter::class)->importFile($this->shopee, $this->csv($this->shopeeExport('Pengembalian Dana/Barang'))->getRealPath(), 'order.csv');
        $order->refresh();
        $this->assertSame(MarketplaceOrderStatus::ReturnRequested, $order->status);
        $this->assertSame(48.0, $this->tshirtM->fresh()->stockIn($this->online));

        // Gudang menerima: kaos M 2 direfund, 2 kembali (1 cacat)
        $itemM = $order->items->firstWhere('marketplace_sku', 'TS-BSC-HTM-M');
        app(MarketplaceOrderService::class)->receiveReturn($order, [$itemM->id => ['returned' => 2, 'restocked' => 2, 'defect' => 1]], '2026-10-24', 'Ukuran tidak sesuai');

        $order->refresh();
        $this->assertSame(MarketplaceOrderStatus::Returned, $order->status);
        $this->assertSame(198000.0, (float) $order->returned_total);
        $this->assertSame(80000.0, (float) $order->returned_cost);
        $variant = $this->tshirtM->fresh();
        $this->assertSame(49.0, $variant->stockIn($this->online));
        $this->assertSame(1.0, $variant->stockIn($this->online, true));

        $this->assertSame(250000.0, $this->balance('marketplace_receivable', $this->shopee));
        $this->assertSame(round(198000 / 1.11, 2), $this->balance('sales_returns'));
        $this->assertSame(40000.0, $this->balance('defect_inventory'));
        $this->assertSame(126000.0, $this->balance('cogs'));
    }

    public function test_cancel_before_shipping_has_no_effect_and_cancel_after_shipping_becomes_return(): void
    {
        MarketplaceProduct::create(['marketplace_store_id' => $this->shopee->id, 'marketplace_sku' => 'PAKET-L3', 'product_variant_id' => $this->tshirtL->id, 'units_per_item' => 3]);
        app(MarketplaceImporter::class)->importFile($this->shopee, $this->csv($this->shopeeExport('Batal', ['shipped' => '']))->getRealPath(), 'order.csv');

        $order = MarketplaceOrder::first();
        $this->assertSame(MarketplaceOrderStatus::Cancelled, $order->status);
        $this->assertSame(50.0, $this->tshirtM->fresh()->stockIn($this->online));
        $this->assertSame(0.0, $this->balance('marketplace_receivable'));

        $order->update(['order_number' => 'X1']);
        app(MarketplaceImporter::class)->importFile($this->shopee, $this->csv($this->shopeeExport('Dikirim'))->getRealPath(), 'order.csv');
        app(MarketplaceImporter::class)->importFile($this->shopee, $this->csv($this->shopeeExport('Batal'))->getRealPath(), 'order.csv');

        $shipped = MarketplaceOrder::where('order_number', '2610250001ABC')->first();
        $this->assertSame(MarketplaceOrderStatus::ReturnRequested, $shipped->status);
        $this->assertStringContainsString('gagal kirim', $shipped->return_reason);
    }

    public function test_settlement_import_posts_fees_and_clears_receivable_with_rebuild(): void
    {
        MarketplaceProduct::create(['marketplace_store_id' => $this->shopee->id, 'marketplace_sku' => 'PAKET-L3', 'product_variant_id' => $this->tshirtL->id, 'units_per_item' => 3]);
        app(MarketplaceImporter::class)->importFile($this->shopee, $this->csv($this->shopeeExport('Selesai'))->getRealPath(), 'order.csv');

        $file = $this->csv([
            ['No. Pesanan', 'Tanggal Dana Dilepaskan', 'Harga Asli Produk', 'Total Diskon Produk', 'Voucher disponsori oleh Penjual', 'Biaya Administrasi', 'Biaya Layanan', 'Biaya Proses Pesanan', 'Ongkir Dibayar Pembeli', 'Gratis Ongkir dari Shopee', 'Ongkir yang Diteruskan oleh Shopee ke Jasa Kirim', 'Total Penghasilan'],
            ['2610250001ABC', '2026-10-27', '500.000', '-52.000', '-10.000', '-28.000', '-13.000', '-1.000', '10.000', '5.000', '-18.000', '393.000'],
            ['', '2026-10-27', '', '', '', '', '', '', '', '', '', '-15.000'],
        ]);

        $settlement = app(MarketplaceSettlementService::class)->importFile($this->shopee, $file->getRealPath(), 'income.csv');
        $this->assertTrue($settlement->isDraft());
        $line = $settlement->lines->first();
        $this->assertSame(448000.0, (float) $line->order_amount);
        $this->assertSame(3000.0, (float) $line->shipping_fee); // 18.000 − 10.000 − 5.000
        $this->assertSame(0.0, (float) $line->adjustment); // 448 − 10 − 28 − 13 − 1 − 3 = 393
        $this->assertSame(-15000.0, (float) $settlement->lines->last()->adjustment);

        app(MarketplaceSettlementService::class)->post($settlement);
        $settlement->refresh();

        $this->assertSame(378000.0, (float) $settlement->net_amount);
        $this->assertSame(0.0, $this->balance('marketplace_receivable', $this->shopee));
        $this->assertSame(378000.0, $this->balance('marketplace_balance'));
        $this->assertSame(10000.0, $this->balance('marketplace_voucher'));
        $this->assertSame(42000.0, $this->balance('marketplace_fee'));
        $this->assertSame(3000.0, $this->balance('marketplace_shipping'));
        $this->assertSame(15000.0, $this->balance('marketplace_adjustment'));
        $this->assertSame(393000.0, (float) MarketplaceOrder::first()->settled_net);

        $marketplaceLines = fn () => JournalLine::whereHas('entry', fn ($q) => $q->whereIn('source_type', ['marketplace_order', 'marketplace_settlement']))
            ->orderBy('account_id')->orderBy('debit')->get(['account_id', 'debit', 'credit', 'reference_type', 'reference_id'])->toArray();
        $before = $marketplaceLines();
        app(LedgerRebuilder::class)->rebuild();
        $this->assertSame($before, $marketplaceLines());
        $this->assertSame(0.0, $this->balance('marketplace_receivable', $this->shopee));

        // Batal pencairan: jurnal dibalik, piutang terbuka lagi
        app(MarketplaceSettlementService::class)->cancel($settlement, 'salah file');
        $this->assertSame(448000.0, $this->balance('marketplace_receivable', $this->shopee));
        $this->assertSame(0.0, (float) MarketplaceOrder::first()->settled_receivable);
    }

    public function test_settlement_blocks_unshipped_orders(): void
    {
        app(MarketplaceImporter::class)->importFile($this->shopee, $this->csv($this->shopeeExport('Perlu Dikirim'))->getRealPath(), 'order.csv');
        $settlement = app(MarketplaceSettlementService::class)->createDraft($this->shopee, today(), [
            ['order_number' => '2610250001ABC', 'order_amount' => 448000, 'commission' => 20000, 'net_amount' => 428000],
        ]);

        $this->expectException(ValidationException::class);
        app(MarketplaceSettlementService::class)->post($settlement);
    }

    public function test_tiktok_export_with_us_dates_and_status_mapping(): void
    {
        $tiktok = MarketplaceStore::create(['platform' => MarketplacePlatform::TikTok, 'name' => 'Garmen TT', 'warehouse_id' => $this->online->id]);
        $file = $this->csv([
            ['Order ID', 'Order Status', 'Seller SKU', 'Product Name', 'Variation', 'Quantity', 'SKU Unit Original Price', 'SKU Subtotal After Discount', 'Created Time', 'Shipped Time', 'Tracking ID'],
            ['Platform unique order ID.', 'Order status', '', '', '', '', '', '', '', '', ''],
            ['577012345678', 'Shipped', 'TS-BSC-HTM-M', 'Kaos', 'M', '3', 'IDR 110.000', 'IDR 297.000', '10/09/2026 11:02:03 PM', '10/11/2026 08:00:00 AM', 'JX123'],
        ]);

        app(MarketplaceImporter::class)->importFile($tiktok, $file->getRealPath(), 'tt.csv');
        $order = MarketplaceOrder::where('marketplace_store_id', $tiktok->id)->first();

        $this->assertSame(MarketplaceOrderStatus::Shipped, $order->status);
        $this->assertSame('2026-10-09', $order->order_date->toDateString());
        $this->assertSame('2026-10-11', $order->shipped_at->toDateString());
        $this->assertSame(297000.0, (float) $order->total);
        $this->assertSame(47.0, $this->tshirtM->fresh()->stockIn($this->online));
    }

    public function test_spreadsheet_reader_parses_numbers_and_xlsx(): void
    {
        $this->assertSame(1250000.0, SpreadsheetReader::number('Rp 1.250.000'));
        $this->assertSame(1250000.5, SpreadsheetReader::number('1,250,000.50'));
        $this->assertSame(-12500.0, SpreadsheetReader::number('-12.500'));
        $this->assertSame(99.5, SpreadsheetReader::number('99,5'));
        $this->assertSame(150000.0, SpreadsheetReader::number('150000'));
        $this->assertSame(150000.0, SpreadsheetReader::number('1.5E5'));

        // XLSX minimal dengan shared strings
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>No. Pesanan</t></si><si><t>Jumlah</t></si><si><t>A1B2</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="C1" t="s"><v>1</v></c></row><row r="2"><c r="A2" t="s"><v>2</v></c><c r="C2"><v>4</v></c></row></sheetData></worksheet>');
        $zip->close();

        $this->assertSame([['No. Pesanan', '', 'Jumlah'], ['A1B2', '', '4']], SpreadsheetReader::read($path));
    }

    public function test_api_connectors_sign_and_normalize_orders(): void
    {
        $this->assertSame(hash_hmac('sha256', '1/api/v2/x100tok9', 'key'), ShopeeConnector::sign('key', '1', '/api/v2/x', 100, 'tok', '9'));
        $this->assertSame(hash_hmac('sha256', 'sec/p'.'a1b2'.'{}'.'sec', 'sec'), TikTokConnector::sign('sec', '/p', ['b' => 2, 'a' => 1, 'sign' => 'x', 'access_token' => 'y'], '{}'));

        $this->shopee->update(['api_enabled' => true, 'api_partner_id' => '1001', 'api_partner_key' => 'secret', 'api_access_token' => 'token', 'shop_id' => '55']);
        Http::fake([
            '*get_order_list*' => Http::response(['response' => ['order_list' => [['order_sn' => 'SN1']], 'more' => false]]),
            '*get_order_detail*' => Http::response(['response' => ['order_list' => [[
                'order_sn' => 'SN1', 'order_status' => 'READY_TO_SHIP', 'create_time' => Carbon::parse('2026-10-24 08:00')->timestamp,
                'buyer_username' => 'sari', 'item_list' => [['item_name' => 'Kaos', 'model_sku' => 'TS-BSC-HTM-M', 'model_name' => 'M', 'model_quantity_purchased' => 2, 'model_discounted_price' => 95000]],
            ]]]]),
        ]);

        $summary = app(MarketplaceSyncService::class)->sync($this->shopee->fresh());
        $this->assertSame(1, $summary['created']);
        $order = MarketplaceOrder::where('order_number', 'SN1')->first();
        $this->assertSame(MarketplaceOrderStatus::ToShip, $order->status);
        $this->assertSame(190000.0, (float) $order->total);
        $this->assertSame('api', $order->source);
    }

    public function test_http_flow_pages_report_and_access(): void
    {
        $this->get(route('marketplace-stores.create'))->assertOk();
        $this->post(route('marketplace-stores.store'), [
            'platform' => 'tiktok', 'name' => 'Garmen TikTok', 'warehouse_id' => $this->online->id, 'tax_rate' => '0', 'is_active' => '1', 'api_enabled' => '0',
        ])->assertRedirect(route('marketplace-stores.index'));
        $tiktok = MarketplaceStore::where('name', 'Garmen TikTok')->firstOrFail();
        $this->get(route('marketplace-stores.edit', $tiktok))->assertOk();

        $this->post(route('marketplace-orders.import.store'), ['marketplace_store_id' => $this->shopee->id, 'file' => $this->csv($this->shopeeExport('Perlu Dikirim'))])
            ->assertRedirect()->assertSessionHas('success');
        $order = MarketplaceOrder::firstOrFail();

        $this->get(route('marketplace-mappings.index'))->assertOk()->assertSee('PAKET-L3');
        $this->post(route('marketplace-mappings.store'), [
            'marketplace_store_id' => $this->shopee->id, 'marketplace_sku' => 'PAKET-L3', 'product_variant_id' => $this->tshirtL->id, 'units_per_item' => 3,
        ])->assertSessionHas('success');
        $this->assertFalse($order->fresh()->load('items')->hasUnmappedItems());

        $this->get(route('marketplace-stock.index', ['store_id' => $this->shopee->id]))->assertOk()->assertSee('PAKET-L3');
        $export = $this->get(route('marketplace-stock.index', ['store_id' => $this->shopee->id, 'export' => 'xlsx']))->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $rows = SpreadsheetReader::read($export->baseResponse->getFile()->getPathname(), 'stok.xlsx');
        $this->assertSame('SKU', $rows[0][0]);
        $this->assertContains('TS-BSC-HTM-M', array_column($rows, 0));

        $this->post(route('marketplace-orders.ship-many'), ['orders' => [$order->id], 'date' => '2026-10-22'])->assertSessionHas('success');
        $this->assertSame(MarketplaceOrderStatus::Shipped, $order->fresh()->status);
        $this->post(route('marketplace-orders.complete', $order))->assertSessionHas('success');
        $this->post(route('marketplace-orders.request-return', $order), ['reason' => 'Salah ukuran'])->assertSessionHas('success');

        $itemL = $order->items()->where('marketplace_sku', 'PAKET-L3')->first();
        $this->post(route('marketplace-orders.return', $order), [
            'date' => '2026-10-24', 'condition' => 'good', 'lines' => [$itemL->id => ['returned' => 1, 'restocked' => 1, 'defect' => 0]],
        ])->assertSessionHas('success');
        $this->assertSame(20.0, $this->tshirtL->fresh()->stockIn($this->online));

        $this->get(route('marketplace-orders.index'))->assertOk()->assertSee('2610250001ABC');
        $this->get(route('marketplace-orders.show', $order))->assertOk()->assertSee('Retur diterima');
        $this->get(route('marketplace-orders.import'))->assertOk();
        $this->get(route('marketplace-orders.template'))->assertOk();

        $this->get(route('marketplace-settlements.create'))->assertOk();
        $this->post(route('marketplace-settlements.store'), ['marketplace_store_id' => $this->shopee->id, 'file' => $this->csv([
            ['No. Pesanan', 'Tanggal Dana Dilepaskan', 'Nilai Pesanan', 'Komisi', 'Biaya Layanan', 'Total Penghasilan'],
            ['2610250001ABC', '28/10/2026', '198000', '12000', '5000', '181000'],
        ])])->assertRedirect();
        $settlement = MarketplaceSettlement::firstOrFail();
        $this->assertSame('2026-10-28', $settlement->date->toDateString());
        $this->get(route('marketplace-settlements.show', $settlement))->assertOk();
        $this->post(route('marketplace-settlements.post', $settlement))->assertSessionHas('success');
        $this->assertSame(0.0, $this->balance('marketplace_receivable', $this->shopee));
        $this->get(route('marketplace-settlements.index'))->assertOk()->assertSee($settlement->number);
        $this->get(route('marketplace-settlements.template'))->assertOk();

        foreach (['stores', 'products', 'orders'] as $view) {
            $this->get(route('reports.marketplace', ['view' => $view, 'from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk();
        }
        $this->get(route('reports.marketplace', ['from' => '2026-10-01', 'to' => '2026-10-31', 'export' => 'xlsx']))->assertOk();
        $this->get(route('reports.marketplace', ['from' => '2026-10-01', 'to' => '2026-10-31', 'export' => 'pdf']))->assertOk();
        $this->get(route('reports.vat', ['period' => '2026-10']))->assertOk();
        $this->get(route('marketplace-stores.index'))->assertOk();
        $this->get(route('dashboard'))->assertOk();

        // Sales boleh kelola pesanan, tidak boleh posting pencairan
        $sales = User::factory()->create(['role' => Role::Sales]);
        $this->actingAs($sales)->get(route('marketplace-orders.index'))->assertOk();
        $this->actingAs($sales)->get(route('marketplace-settlements.create'))->assertForbidden();
        $finance = User::factory()->create(['role' => Role::Keuangan]);
        $this->actingAs($finance)->get(route('marketplace-settlements.create'))->assertOk();
        $this->actingAs($finance)->get(route('marketplace-orders.import'))->assertForbidden();
    }

    public function test_margin_report_numbers(): void
    {
        MarketplaceProduct::create(['marketplace_store_id' => $this->shopee->id, 'marketplace_sku' => 'PAKET-L3', 'product_variant_id' => $this->tshirtL->id, 'units_per_item' => 3]);
        app(MarketplaceImporter::class)->importFile($this->shopee, $this->csv($this->shopeeExport('Selesai'))->getRealPath(), 'order.csv');
        $settlement = app(MarketplaceSettlementService::class)->createDraft($this->shopee, Carbon::parse('2026-10-28'), [
            ['order_number' => '2610250001ABC', 'order_amount' => 448000, 'voucher' => 8000, 'commission' => 30000, 'shipping_fee' => 2000, 'net_amount' => 400000],
        ]);
        app(MarketplaceSettlementService::class)->post($settlement);

        $data = app(MarketplaceReport::class)->build(Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
        $row = $data['rows']->first();
        $this->assertSame(448000.0, $row['sales']);
        $this->assertSame(30000.0, $row['fees']);
        $this->assertSame(8000.0, $row['adjustment']); // 448 − 400 − 8 − 30 − 2
        $this->assertSame(448000.0 - 8000 - 30000 - 2000 - 8000 - 206000, $row['margin']);
    }

    public function test_wrong_item_return_records_loss_and_claim_compensation_is_settled(): void
    {
        MarketplaceProduct::create(['marketplace_store_id' => $this->shopee->id, 'marketplace_sku' => 'PAKET-L3', 'product_variant_id' => $this->tshirtL->id, 'units_per_item' => 3]);
        app(MarketplaceImporter::class)->importFile($this->shopee, $this->csv($this->shopeeExport('Selesai'))->getRealPath(), 'order.csv');
        $order = MarketplaceOrder::with('items')->first();
        $itemM = $order->items->firstWhere('marketplace_sku', 'TS-BSC-HTM-M');

        // Pembeli mengembalikan barang lain (bukan kaos kita): refund 2 pcs, tidak ada yang masuk stok
        $this->post(route('marketplace-orders.request-return', $order), ['reason' => 'Tidak sesuai'])->assertSessionHas('success');
        $this->post(route('marketplace-orders.return', $order), [
            'date' => '2026-10-24', 'condition' => 'wrong_item', 'lines' => [$itemM->id => ['returned' => 2, 'restocked' => 0, 'defect' => 0]],
        ])->assertSessionHas('success');

        $order->refresh();
        $this->assertSame(48.0, $this->tshirtM->fresh()->stockIn($this->online));
        $this->assertSame(80000.0, (float) $order->returned_lost_cost);
        $this->assertSame(80000.0, $this->balance('marketplace_loss'));
        $this->assertSame(126000.0, $this->balance('cogs')); // HPP hanya barang yang benar-benar terjual
        $this->assertTrue($order->canOpenClaim());

        $this->post(route('marketplace-orders.claim', $order), ['reason' => 'Tiket #123, video unboxing'])->assertSessionHas('success');
        $this->post(route('marketplace-orders.claim.resolve', $order), ['result' => 'won', 'claim_amount' => 150000, 'date' => '2026-10-26'])->assertSessionHas('success');

        $order->refresh();
        $this->assertSame(-70000.0, $this->balance('marketplace_loss'));
        $this->assertSame(250000.0 + 150000, $order->outstandingReceivable());

        $settlement = app(MarketplaceSettlementService::class)->createDraft($this->shopee, Carbon::parse('2026-10-28'), [
            ['order_number' => '2610250001ABC', 'order_amount' => 250000, 'commission' => 10000, 'net_amount' => 240000],
            ['order_number' => '2610250001ABC', 'description' => 'Kompensasi banding', 'adjustment' => 150000, 'net_amount' => 150000],
        ]);
        app(MarketplaceSettlementService::class)->post($settlement);
        $this->assertSame(0.0, $this->balance('marketplace_receivable', $this->shopee));
        $this->assertSame(0.0, $this->balance('marketplace_adjustment'));
        $this->assertSame(0.0, $order->fresh()->outstandingReceivable());

        $this->get(route('marketplace-orders.show', $order))->assertOk()->assertSee('Banding Diterima');

        $lines = fn () => JournalLine::whereHas('entry', fn ($q) => $q->where('source_type', 'marketplace_order'))->orderBy('account_id')->orderBy('debit')->get(['account_id', 'debit', 'credit'])->toArray();
        $before = $lines();
        app(LedgerRebuilder::class)->rebuild();
        $this->assertSame($before, $lines());
    }

    public function test_partial_refund_amount_without_goods_back(): void
    {
        MarketplaceProduct::create(['marketplace_store_id' => $this->shopee->id, 'marketplace_sku' => 'PAKET-L3', 'product_variant_id' => $this->tshirtL->id, 'units_per_item' => 3]);
        app(MarketplaceImporter::class)->importFile($this->shopee, $this->csv($this->shopeeExport('Selesai'))->getRealPath(), 'order.csv');
        $order = MarketplaceOrder::first();

        // Pembeli komplain kurang 1 pcs, platform refund Rp 50.000 tanpa pengembalian barang
        app(MarketplaceOrderService::class)->receiveReturn($order, [], '2026-10-24', 'Barang kurang', 'refund_only', 50000);
        $order->refresh();
        $this->assertSame(50000.0, (float) $order->returned_total);
        $this->assertSame(0.0, (float) $order->returned_lost_cost);
        $this->assertSame(398000.0, $this->balance('marketplace_receivable', $this->shopee));
        $this->assertSame(50000.0, $this->balance('sales_returns'));
    }
}
