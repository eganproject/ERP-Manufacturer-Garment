<?php

namespace Tests\Feature;

use App\Enums\FixedAssetStatus;
use App\Enums\InvoiceStatus;
use App\Enums\Role;
use App\Models\Account;
use App\Models\AssetCategory;
use App\Models\Bill;
use App\Models\DepreciationRun;
use App\Models\FixedAsset;
use App\Models\JournalLine;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Accounting\LedgerRebuilder;
use App\Services\DepreciationService;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class FixedAssetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-25 10:00:00');
        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create());
        app(LedgerRebuilder::class)->rebuild(); // jurnal saldo awal stok data master
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

    private function category(string $code): AssetCategory
    {
        return AssetCategory::where('code', $code)->firstOrFail();
    }

    private function createAsset(array $data): FixedAsset
    {
        $this->post(route('fixed-assets.store'), $data)->assertSessionHasNoErrors();

        return FixedAsset::latest('id')->first();
    }

    /** Mesin tunai (garis lurus 4 th), kendaraan kredit + PPN (saldo menurun 8 th), gedung saldo awal. */
    private function threeAssets(): array
    {
        $machine = $this->createAsset([
            'acquisition_type' => 'cash', 'name' => 'Mesin jahit Juki', 'asset_category_id' => $this->category('MSN')->id,
            'acquisition_date' => '2026-07-10', 'cost' => 48000000, 'cash_account_id' => Account::where('code', '1101')->value('id'),
            'depreciation_method' => 'straight_line', 'useful_life_months' => 48,
        ]);
        $vehicle = $this->createAsset([
            'acquisition_type' => 'credit', 'name' => 'Mobil box', 'asset_category_id' => $this->category('KND')->id,
            'acquisition_date' => '2026-08-05', 'cost' => 120000000, 'tax_rate' => 11, 'supplier_id' => Supplier::first()->id,
            'reference' => 'FK-001', 'depreciation_method' => 'declining_balance', 'useful_life_months' => 96,
        ]);
        $building = $this->createAsset([
            'acquisition_type' => 'opening', 'name' => 'Gedung pabrik', 'asset_category_id' => $this->category('BGN')->id,
            'acquisition_date' => '2016-10-01', 'cost' => 600000000, 'opening_accumulated' => 300000000,
            'depreciation_start' => '2026-10-01', 'depreciation_method' => 'straight_line', 'useful_life_months' => 240,
        ]);

        return [$machine, $vehicle, $building];
    }

    public function test_acquisition_journals_for_cash_credit_and_opening_assets(): void
    {
        $equity = $this->balance('3901');
        [$machine, $vehicle, $building] = $this->threeAssets();

        $this->assertSame('AT-202607-0001', $machine->number);
        $this->assertSame(48000000.0, $this->balance('1503'));
        $this->assertSame(-48000000.0, $this->balance('1101'));

        // Kredit: tagihan supplier otomatis (DPP + PPN), hutang belum ditagih kembali nol
        $bill = Bill::findOrFail($vehicle->bill_id);
        $this->assertSame(133200000.0, (float) $bill->total);
        $this->assertSame('FK-001', $bill->supplier_invoice_number);
        $this->assertSame($vehicle->id, $bill->fixedAsset->id);
        $this->assertSame(120000000.0, $this->balance('1504'));
        $this->assertSame(13200000.0, $this->balance('1401'));
        $this->assertSame(-133200000.0, $this->balance('2101'));
        $this->assertSame(0.0, $this->balance('2102'));

        // Saldo awal: aset bruto & akumulasi awal, lawan ekuitas saldo awal sebesar nilai buku
        $this->assertSame(600000000.0, $this->balance('1502'));
        $this->assertSame(-300000000.0, $this->balance('1509'));
        $this->assertSame(-300000000.0, round($this->balance('3901') - $equity, 2));
        $this->assertSame(300000000.0, $building->bookValue());

        // Tagihan aset tidak bisa dibatalkan dari menu tagihan
        $this->post(route('bills.cancel', $bill))->assertSessionHasErrors('status');
        $this->get(route('bills.show', $bill))->assertOk()->assertSee($vehicle->number);
    }

    public function test_schedules_for_straight_line_declining_balance_and_opening_assets(): void
    {
        [$machine, $vehicle, $building] = $this->threeAssets();
        $service = app(DepreciationService::class);

        $sl = $service->schedule($machine);
        $this->assertCount(48, $sl);
        $this->assertSame(1000000.0, $sl->first()['amount']);
        $this->assertSame('2026-07', $sl->first()['period']->format('Y-m'));
        $this->assertSame(48000000.0, round($sl->sum('amount'), 2));
        $this->assertSame(0.0, $sl->last()['book_value']);

        // Saldo menurun 8 th = 25%/th dari nilai buku awal tahun aset
        $db = $service->schedule($vehicle);
        $this->assertCount(96, $db);
        $this->assertSame(2500000.0, $db[0]['amount']);
        $this->assertSame(2500000.0, $db[11]['amount']);
        $this->assertSame(1875000.0, $db[12]['amount']); // (120jt − 30jt) × 25% / 12
        $this->assertSame(120000000.0, round($db->sum('amount'), 2));
        $this->assertSame(0.0, $db->last()['book_value']);

        // Aset lama: sisa nilai buku dibagi sisa masa manfaat (120 bulan)
        $old = $service->schedule($building);
        $this->assertCount(120, $old);
        $this->assertSame(2500000.0, $old->first()['amount']);
        $this->assertSame(300000000.0, round($old->sum('amount'), 2));
    }

    public function test_monthly_depreciation_runs_with_catch_up_and_ordering_rules(): void
    {
        [$machine, $vehicle, $building] = $this->threeAssets();

        // September: mesin Jul–Sep (3 bulan disusulkan), kendaraan Agu–Sep, gedung belum mulai
        $this->post(route('depreciation-runs.store'), ['period' => '2026-09'])->assertSessionHasNoErrors();
        $sep = DepreciationRun::latest('id')->first();
        $this->assertSame('2026-09-30', $sep->date->toDateString());
        $this->assertSame(8000000.0, (float) $sep->total);
        $this->assertSame(2, $sep->asset_count);
        $this->assertSame(5, $sep->lines()->count());
        $this->assertSame(3000000.0, $this->balance('5202'));
        $this->assertSame(5000000.0, $this->balance('6107'));
        $this->assertSame(3000000.0, (float) $machine->fresh()->accumulated_depreciation);

        // Bulan sebelum posting terakhir / bulan depan ditolak
        $this->post(route('depreciation-runs.store'), ['period' => '2026-08'])->assertSessionHasErrors('period');
        $this->post(route('depreciation-runs.store'), ['period' => '2026-11'])->assertSessionHasErrors('period');
        $this->post(route('depreciation-runs.store'), ['period' => '2026-09'])->assertSessionHasErrors('period'); // tidak ada lagi

        // Oktober lewat perintah terjadwal
        Carbon::setTestNow('2026-11-01 01:00:00');
        $this->artisan('assets:depreciate')->assertSuccessful();
        $oct = DepreciationRun::latest('id')->first();
        $this->assertSame('2026-10-01', $oct->period->toDateString());
        $this->assertSame(6000000.0, (float) $oct->total); // 1jt + 2,5jt + 2,5jt
        $this->assertSame(302500000.0, (float) $building->fresh()->accumulated_depreciation);
        $this->assertSame(-(300000000.0 + 8000000 + 6000000), $this->balance('1509'));

        // Hanya posting terakhir yang bisa dibatalkan
        $this->post(route('depreciation-runs.cancel', $sep), ['reason' => 'salah'])->assertSessionHasErrors('status');
        $this->post(route('depreciation-runs.cancel', $oct), ['reason' => 'koreksi'])->assertSessionHasNoErrors();
        $this->assertSame(0, $oct->lines()->count());
        $this->assertSame(300000000.0, (float) $building->fresh()->accumulated_depreciation);
        $this->assertSame(-(300000000.0 + 8000000), $this->balance('1509'));

        $this->get(route('depreciation-runs.index'))->assertOk()->assertSee('Oktober 2026');
        $this->get(route('depreciation-runs.show', $sep))->assertOk()->assertSee('Mesin jahit Juki');
        $this->get(route('fixed-assets.show', $machine))->assertOk()->assertSee($sep->number)->assertSee('Proyeksi');
    }

    public function test_disposal_with_loss_requires_depreciation_and_can_be_reversed(): void
    {
        [$machine] = $this->threeAssets();
        $bank = Account::where('code', '1102')->value('id');

        // Belum disusutkan s.d. bulan lalu → ditolak
        $this->post(route('fixed-assets.dispose', $machine), ['disposal_date' => '2026-10-20', 'disposal_type' => 'sold', 'disposal_proceeds' => 40000000, 'disposal_account_id' => $bank])
            ->assertSessionHasErrors('disposal_date');

        $this->post(route('depreciation-runs.store'), ['period' => '2026-09'])->assertSessionHasNoErrors();

        // Dijual 40jt, nilai buku 48jt − 3jt = 45jt → rugi 5jt
        $this->post(route('fixed-assets.dispose', $machine), ['disposal_date' => '2026-10-20', 'disposal_type' => 'sold', 'disposal_proceeds' => 40000000, 'disposal_account_id' => $bank])
            ->assertSessionHasNoErrors();
        $machine->refresh();
        $this->assertSame(FixedAssetStatus::Disposed, $machine->status);
        $this->assertSame(-5000000.0, $machine->disposalGain());
        $this->assertSame(0.0, $this->balance('1503'));
        $this->assertSame(5000000.0, $this->balance('8104'));
        $this->assertSame(40000000.0, $this->balance('1102'));

        // Posting penyusutan berikutnya tidak menyusutkan aset yang sudah dilepas; pembatalan posting yang memuatnya ditolak
        $this->assertTrue(app(DepreciationService::class)->preview(Carbon::parse('2026-10-01'))->every(fn ($row) => $row['asset']->id !== $machine->id));
        $this->post(route('depreciation-runs.cancel', DepreciationRun::first()), ['reason' => 'x'])->assertSessionHasErrors('status');

        $this->post(route('fixed-assets.cancel-disposal', $machine), ['reason' => 'Batal jual'])->assertSessionHasNoErrors();
        $this->assertSame(FixedAssetStatus::Active, $machine->fresh()->status);
        $this->assertSame(48000000.0, $this->balance('1503'));
        $this->assertSame(0.0, $this->balance('8104'));

        // Dihapus (rusak) tanpa hasil → rugi sebesar nilai buku
        $this->post(route('fixed-assets.dispose', $machine), ['disposal_date' => '2026-10-21', 'disposal_type' => 'scrapped'])->assertSessionHasNoErrors();
        $this->assertSame(45000000.0, $this->balance('8104'));
    }

    public function test_cancel_asset_reverses_journal_and_supplier_bill(): void
    {
        [$machine, $vehicle] = $this->threeAssets();

        $this->post(route('fixed-assets.cancel', $vehicle), ['reason' => 'Salah input'])->assertSessionHasNoErrors();
        $this->assertSame(FixedAssetStatus::Cancelled, $vehicle->fresh()->status);
        $this->assertSame(InvoiceStatus::Cancelled, Bill::find($vehicle->bill_id)->status);
        $this->assertSame(0.0, $this->balance('1504'));
        $this->assertSame(0.0, $this->balance('2101'));
        $this->assertSame(0.0, $this->balance('1401'));

        // Aset yang sudah disusutkan tidak bisa dibatalkan
        $this->post(route('depreciation-runs.store'), ['period' => '2026-09'])->assertSessionHasNoErrors();
        $this->post(route('fixed-assets.cancel', $machine), ['reason' => 'x'])->assertSessionHasErrors('status');
    }

    public function test_closed_period_blocks_depreciation_and_rebuild_reproduces_ledger(): void
    {
        [$machine] = $this->threeAssets();
        $this->post(route('depreciation-runs.store'), ['period' => '2026-09'])->assertSessionHasNoErrors();
        $this->post(route('fixed-assets.dispose', $machine), ['disposal_date' => '2026-10-20', 'disposal_type' => 'sold', 'disposal_proceeds' => 46000000, 'disposal_account_id' => Account::where('code', '1101')->value('id')])
            ->assertSessionHasNoErrors();
        $this->assertSame(-1000000.0, $this->balance('7102')); // laba 1jt

        $codes = ['1101', '1401', '1502', '1503', '1504', '1509', '2101', '2102', '3901', '5202', '6107', '7102', '8104'];
        $before = collect($codes)->mapWithKeys(fn ($c) => [$c => $this->balance($c)]);
        app(LedgerRebuilder::class)->rebuild();
        $this->assertEquals($before->all(), collect($codes)->mapWithKeys(fn ($c) => [$c => $this->balance($c)])->all());
        $this->assertSame(0.0, round((float) JournalLine::sum(DB::raw('debit - credit')), 2));

        // Tutup buku September: posting September tidak bisa dibatalkan lagi
        foreach ([7, 8, 9] as $month) {
            $this->post(route('periods.close'), ['year' => 2026, 'month' => $month])->assertSessionHasNoErrors();
        }
        $this->post(route('fixed-assets.cancel-disposal', $machine), ['reason' => 'x'])->assertSessionHasNoErrors(); // pembalik bertanggal hari ini
        $this->post(route('depreciation-runs.cancel', DepreciationRun::first()), ['reason' => 'x'])->assertSessionHasErrors('date');
    }

    public function test_pages_and_access(): void
    {
        [$machine, $vehicle, $building] = $this->threeAssets();

        foreach ([
            route('fixed-assets.index'), route('fixed-assets.index', ['export' => 'xlsx']), route('fixed-assets.index', ['export' => 'pdf']),
            route('fixed-assets.create'), route('fixed-assets.create', ['type' => 'credit']), route('fixed-assets.create', ['type' => 'opening']),
            route('fixed-assets.show', $machine), route('fixed-assets.show', $building), route('fixed-assets.edit', $machine),
            route('asset-categories.index'), route('asset-categories.create'), route('asset-categories.edit', $this->category('MSN')),
            route('depreciation-runs.index'), route('depreciation-runs.index', ['period' => '2026-08']),
        ] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get(route('fixed-assets.index'))->assertSee('Gedung pabrik')->assertSee('Rp 768.000.000');

        // Ubah parameter penyusutan sebelum diposting
        $this->put(route('fixed-assets.update', $machine), ['name' => 'Mesin jahit Juki DDL', 'depreciation_method' => 'straight_line', 'useful_life_months' => 60, 'location' => 'Lantai 2'])
            ->assertSessionHasNoErrors();
        $this->assertSame(60, $machine->fresh()->useful_life_months);

        // Kategori baru
        $this->post(route('asset-categories.store'), [
            'code' => 'GDG', 'name' => 'Peralatan Gudang', 'depreciation_method' => 'straight_line', 'useful_life_months' => 48, 'residual_rate' => 10,
            'asset_account_id' => Account::where('code', '1503')->value('id'), 'accumulated_account_id' => Account::where('code', '1509')->value('id'),
            'expense_account_id' => Account::where('code', '5202')->value('id'), 'is_active' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertSame(10.0, (float) $this->category('GDG')->residual_rate);

        $this->actingAs(User::factory()->role(Role::Gudang)->create());
        $this->get(route('fixed-assets.index'))->assertForbidden();
        $this->post(route('depreciation-runs.store'), ['period' => '2026-09'])->assertForbidden();

        $keuangan = User::factory()->role(Role::Keuangan)->create();
        $this->actingAs($keuangan);
        $this->get(route('dashboard'))->assertOk()->assertSee('Penyusutan aset belum diposting');
        $this->post(route('depreciation-runs.store'), ['period' => '2026-09'])->assertSessionHasNoErrors();
    }
}
