<?php

namespace Tests\Feature;

use App\Enums\QuotationStatus;
use App\Enums\Role;
use App\Enums\SalesOrderStatus;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Reports\WorkQueue;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class QuotationTest extends TestCase
{
    use RefreshDatabase;

    private ProductVariant $variant;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-25 10:00:00');
        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->role(Role::Sales)->create());
        $this->variant = Product::where('code', 'TS-BSC')->with('variants')->first()->variants->first();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function createQuotation(array $override = []): Quotation
    {
        $this->post(route('quotations.store'), $override + [
            'customer_id' => Customer::first()->id,
            'quotation_date' => '2026-10-25',
            'subject' => 'Kaos event',
            'lead_time_days' => 10,
            'discount' => 100000,
            'tax_rate' => 11,
            'items' => [['product_variant_id' => $this->variant->id, 'quantity' => 100, 'price' => 65000, 'description' => 'Sablon 1 warna']],
        ])->assertSessionHasNoErrors();

        return Quotation::latest('id')->first();
    }

    public function test_quotation_lifecycle_to_sales_order(): void
    {
        $quotation = $this->createQuotation();

        $this->assertSame('PNW-202610-0001', $quotation->number);
        $this->assertSame(QuotationStatus::Draft, $quotation->status);
        $this->assertSame('2026-11-08', $quotation->valid_until->toDateString()); // default 14 hari
        $this->assertEquals(6400000, (float) $quotation->subtotal - (float) $quotation->discount);
        $this->assertEquals(704000, (float) $quotation->tax_amount);
        $this->assertEquals(7104000, (float) $quotation->total);

        $this->get(route('quotations.index'))->assertOk()->assertSee($quotation->number);
        $this->get(route('quotations.show', $quotation))->assertOk()->assertSee('Sablon 1 warna')->assertSee('Jadikan Sales Order');
        $this->get(route('quotations.edit', $quotation))->assertOk();
        $this->get(route('quotations.pdf', $quotation))->assertOk()->assertHeader('content-type', 'application/pdf');

        $this->post(route('quotations.send', $quotation))->assertSessionHasNoErrors();
        $quotation->refresh();
        $this->assertSame(QuotationStatus::Sent, $quotation->status);
        $this->assertNotNull($quotation->sent_at);
        // Terkirim = terkunci
        $this->get(route('quotations.edit', $quotation))->assertForbidden();
        $this->assertSame(1, app(WorkQueue::class)->for(auth()->user())->where('label', 'Penawaran menunggu jawaban pelanggan')->first()['count']);

        $this->post(route('quotations.convert', $quotation), ['order_date' => '2026-10-27'])->assertSessionHasNoErrors();
        $order = SalesOrder::first();
        $this->assertSame(SalesOrderStatus::Draft, $order->status);
        $this->assertSame($quotation->id, $order->quotation_id);
        $this->assertSame('2026-11-06', $order->delivery_date->toDateString()); // order + estimasi 10 hari
        $this->assertEquals(100000, (float) $order->discount);
        $this->assertEquals(6400000, (float) $order->total);
        $this->assertEquals(100, (float) $order->items()->first()->quantity);
        $this->assertSame(QuotationStatus::Accepted, $quotation->fresh()->status);

        // Tidak bisa dikonversi dua kali
        $this->post(route('quotations.convert', $quotation))->assertSessionHasErrors('status');
        $this->get(route('sales-orders.show', $order))->assertOk()->assertSee($quotation->number);
    }

    public function test_revision_rejection_and_expiry(): void
    {
        $quotation = $this->createQuotation();
        $this->post(route('quotations.send', $quotation));

        $this->post(route('quotations.revise', $quotation))->assertRedirect();
        $revision = Quotation::latest('id')->first();
        $this->assertSame('PNW-202610-0001-R1', $revision->number);
        $this->assertSame(1, $revision->revision);
        $this->assertSame(QuotationStatus::Draft, $revision->status);
        $this->assertSame(QuotationStatus::Revised, $quotation->fresh()->status);
        $this->assertCount(1, $revision->items);

        // Ubah harga revisi lalu kirim, lalu pelanggan menolak
        $this->put(route('quotations.update', $revision), [
            'customer_id' => $revision->customer_id, 'quotation_date' => '2026-10-25', 'valid_until' => '2026-10-30', 'tax_rate' => 0,
            'items' => [['product_variant_id' => $this->variant->id, 'quantity' => 100, 'price' => 60000]],
        ])->assertSessionHasNoErrors();
        $this->assertEquals(6000000, (float) $revision->fresh()->total);
        $this->post(route('quotations.send', $revision));
        $this->post(route('quotations.reject', $revision), ['reason' => 'Harga masih tinggi'])->assertSessionHasNoErrors();
        $this->assertSame(QuotationStatus::Rejected, $revision->fresh()->status);

        // Revisi ke-2 dari penawaran yang ditolak
        $this->post(route('quotations.revise', $revision->fresh()))->assertRedirect();
        $second = Quotation::latest('id')->first();
        $this->assertSame('PNW-202610-0001-R2', $second->number);
        $this->assertSame(QuotationStatus::Rejected, $revision->fresh()->status);

        // Nomor penawaran baru tetap berurutan
        $other = $this->createQuotation();
        $this->assertSame('PNW-202610-0002', $other->number);

        // Kedaluwarsa: tidak bisa dijadikan SO, muncul di filter kedaluwarsa
        $this->post(route('quotations.send', $second));
        Carbon::setTestNow('2026-12-01 09:00:00');
        $this->assertTrue($second->fresh()->isExpired());
        $this->post(route('quotations.convert', $second))->assertSessionHasErrors('status');
        $this->get(route('quotations.index', ['status' => 'expired']))->assertOk()->assertSee($second->number)->assertSee('Kedaluwarsa');
        $this->post(route('quotations.revise', $second))->assertRedirect();
        $this->assertSame('PNW-202610-0001-R3', Quotation::latest('id')->first()->number);
    }

    public function test_draft_delete_and_permissions(): void
    {
        $quotation = $this->createQuotation();
        $this->delete(route('quotations.destroy', $quotation))->assertRedirect();
        $this->assertDatabaseMissing('quotations', ['id' => $quotation->id]);

        $quotation = $this->createQuotation();
        $this->actingAs(User::factory()->role(Role::Gudang)->create());
        $this->get(route('quotations.index'))->assertOk(); // gudang boleh lihat (sales.view)
        $this->post(route('quotations.send', $quotation))->assertForbidden();
        $this->actingAs(User::factory()->role(Role::Purchasing)->create());
        $this->get(route('quotations.index'))->assertForbidden();
    }
}
