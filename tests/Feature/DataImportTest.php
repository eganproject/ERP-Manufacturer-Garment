<?php

namespace Tests\Feature;

use App\Enums\EmployeeWorkType;
use App\Enums\JournalType;
use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Import\ImportRegistry;
use App\Support\LedgerAccounts;
use App\Support\SpreadsheetReader;
use App\Support\XlsReader;
use App\Support\XlsxWriter;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DataImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-28 09:00:00');
        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create());
    }

    /** @param list<list<mixed>> $rows */
    private function xlsx(array $rows, string $name = 'data.xlsx'): UploadedFile
    {
        $path = (new XlsxWriter)->sheet('Data', $rows)->save(tempnam(sys_get_temp_dir(), 'imp').'.xlsx');

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_reader_supports_xls_files(): void
    {
        $rows = SpreadsheetReader::read(base_path('tests/Fixtures/sample.xls'), 'sample.xls');
        $this->assertCount(400, $rows);
        $this->assertSame(['PLG-001', 'Toko Ünïcode – Jaya 東京', '1250000', '1.1250', '46296', 'TRUE'], array_slice($rows[1], 0, 6));
        $this->assertSame(1.125, SpreadsheetReader::number($rows[1][3]));
        $this->assertSame('2026-10-01', SpreadsheetReader::date($rows[1][4])->toDateString());
        $this->assertSame(-12.5, SpreadsheetReader::number($rows[2][2]));
        // String panjang yang terpotong record CONTINUE tetap utuh
        $this->assertSame(str_repeat('Nama panjang 399 ', 8).'é', $rows[399][1]);

        // File .xls buatan LibreOffice/Excel menyimpan hasil rumus
        $rows = SpreadsheetReader::read(base_path('tests/Fixtures/libreoffice.xls'), 'x.xls');
        $this->assertSame(['P1', 'Kaos ✓ Ünï', '150000', '2.3750', '46037', 'P1-X', '300000'], $rows[1]);
        $this->assertTrue(XlsReader::isXls(base_path('tests/Fixtures/libreoffice.xls')));
    }

    public function test_import_customers_from_excel_creates_and_updates(): void
    {
        $this->get(route('imports.index'))->assertOk()->assertSee('Pelanggan')->assertSee('Stok Awal');
        $this->get(route('imports.show', 'customers'))->assertOk()->assertSee('Unduh Template Excel');

        $this->post(route('imports.store', 'customers'), ['file' => $this->xlsx([
            ['Kode*', 'Nama*', 'Kontak', 'Telepon', 'Email', 'Alamat', 'NPWP', 'Termin (hari)', 'Aktif'],
            ['PLG-100', 'CV Baru Jaya', 'Rudi', '081200001111', 'rudi@baru.id', 'Bandung', '', 45, 'Ya'],
            ['CUS-001', 'Toko Busana Sejahtera (Pusat)', '', '', '', '', '', '', 'Tidak'],
            ['', '', '', '', '', '', '', '', ''],
        ])])->assertRedirect(route('imports.show', 'customers'))->assertSessionHas('success', 'Impor Pelanggan selesai: 1 data baru, 1 diperbarui.');

        $new = Customer::where('code', 'PLG-100')->firstOrFail();
        $this->assertSame(45, $new->payment_terms);
        $this->assertSame('081200001111', $new->phone);
        $this->assertTrue($new->is_active);

        $old = Customer::where('code', 'CUS-001')->firstOrFail();
        $this->assertSame('Toko Busana Sejahtera (Pusat)', $old->name);
        $this->assertSame('Andi', $old->contact_person, 'Sel kosong tidak mengubah nilai lama');
        $this->assertFalse($old->is_active);
        $this->assertTrue(ActivityLog::where('event', 'import')->exists());
    }

    public function test_any_error_rejects_whole_file_with_row_numbers(): void
    {
        $before = Customer::count();

        $this->post(route('imports.store', 'customers'), ['file' => $this->xlsx([
            ['Daftar Pelanggan'],
            ['Kode', 'Nama', 'Email', 'Termin (hari)', 'Aktif'],
            ['PLG-200', 'Valid', '', '', ''],
            ['PLG-201', 'Email salah', 'bukan-email', '', ''],
            ['PLG-200', 'Dobel', '', '', ''],
            ['PLG-202', '', '', 'tiga puluh', 'mungkin'],
        ])])->assertSessionHasErrors('file');

        $errors = session('errors')->get('file');
        $this->assertStringContainsString('ditemukan 5 kesalahan', $errors[0]);
        $this->assertContains('Baris 4: email "bukan-email" tidak valid.', $errors);
        $this->assertContains('Baris 5: "PLG-200" sudah ada di baris 3 (tidak boleh dobel dalam satu file).', $errors);
        $this->assertContains('Baris 6: kolom "Nama" wajib diisi.', $errors);
        $this->assertSame($before, Customer::count());

        // Judul kolom wajib tidak ada
        $this->post(route('imports.store', 'customers'), ['file' => $this->xlsx([['Nama Pelanggan', 'Alamat'], ['A', 'B']])])
            ->assertSessionHasErrors('file');
        $this->assertStringContainsString('Judul kolom tidak ditemukan', session('errors')->first('file'));
    }

    public function test_csv_still_supported_for_suppliers(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sup').'.csv';
        file_put_contents($path, "\xEF\xBB\xBFKode;Nama;Termin (hari);Vendor Maklon\nMKL-009;Jahit Bu Tini;14;ya\n");

        $this->post(route('imports.store', 'suppliers'), ['file' => new UploadedFile($path, 'supplier.csv', 'text/csv', null, true)])
            ->assertSessionHas('success');

        $supplier = Supplier::where('code', 'MKL-009')->firstOrFail();
        $this->assertTrue($supplier->is_subcontractor);
        $this->assertSame(14, $supplier->payment_terms);
    }

    public function test_import_materials_and_products_with_auto_units_and_categories(): void
    {
        $this->post(route('imports.store', 'materials'), ['file' => $this->xlsx([
            ['Kode', 'Nama', 'Kategori', 'Satuan', 'Warna', 'Harga Beli', 'Stok Minimum', 'Kode Supplier Utama'],
            ['KN-FLC-ABU', 'Kain Fleece', 'Kain Rajut', 'Kilogram', 'Abu', '98.500', 1.125, 'SUP-001'],
        ])])->assertSessionHas('success');

        $material = Material::where('code', 'KN-FLC-ABU')->firstOrFail();
        $this->assertSame('KG', $material->unit->code, 'Satuan dicari dari nama');
        $this->assertSame(98500.0, (float) $material->price);
        $this->assertSame(1.13, round((float) $material->min_stock, 2));
        $this->assertSame('SUP-001', $material->defaultSupplier->code);
        $this->assertTrue(Category::ofType('material')->where('name', 'Kain Rajut')->exists());

        $this->post(route('imports.store', 'materials'), ['file' => $this->xlsx([
            ['Kode', 'Nama', 'Satuan', 'Kode Supplier Utama'],
            ['X-1', 'Bahan X', 'PCS', 'SUP-404'],
        ])])->assertSessionHasErrors('file');
        $this->assertContains('Baris 2: supplier dengan kode "SUP-404" belum terdaftar.', session('errors')->get('file'));

        $this->post(route('imports.store', 'products'), ['file' => $this->xlsx([
            ['Kode Produk', 'Nama Produk', 'Kategori', 'Satuan', 'Harga Jual', 'Tarif Borongan per Pcs', 'SKU', 'Ukuran', 'Warna', 'Harga Varian'],
            ['HD-ZIP', 'Hoodie Zipper', 'Hoodie', 'Potong', 185000, 9000, 'HD-ZIP-ABU-M', 'M', 'Abu', ''],
            ['HD-ZIP', '', '', '', '', '', 'HD-ZIP-ABU-XXL', 'XXL', 'Abu', 195000],
            ['TS-BSC', 'Kaos Basic Cotton 30s', '', '', 105000, '', 'TS-BSC-HTM-M', '', '', ''],
        ])])->assertSessionHas('success', 'Impor Produk & Varian selesai: 2 data baru, 1 diperbarui.');

        $hoodie = Product::where('code', 'HD-ZIP')->firstOrFail();
        $this->assertSame(2, $hoodie->variants()->count());
        $this->assertSame(9000.0, (float) $hoodie->full_garment_rate);
        $this->assertTrue(Unit::where('name', 'Potong')->where('code', 'POTONG')->exists());
        $this->assertSame(195000.0, ProductVariant::where('sku', 'HD-ZIP-ABU-XXL')->first()->selling_price);
        $this->assertSame(105000.0, (float) Product::where('code', 'TS-BSC')->value('price'));

        // SKU milik produk lain & produk baru tanpa satuan ditolak
        $this->post(route('imports.store', 'products'), ['file' => $this->xlsx([
            ['Kode Produk', 'Nama Produk', 'SKU', 'Ukuran'],
            ['HD-ZIP', 'Hoodie Zipper', 'TS-BSC-HTM-M', 'M'],
            ['NEW-1', 'Baru', 'NEW-1-S', 'S'],
        ])])->assertSessionHasErrors('file');
        $errors = session('errors')->get('file');
        $this->assertContains('Baris 2: SKU "TS-BSC-HTM-M" sudah dipakai produk TS-BSC.', $errors);
        $this->assertContains('Baris 3: kolom "Satuan" wajib diisi untuk produk baru.', $errors);
    }

    public function test_import_employees(): void
    {
        $this->post(route('imports.store', 'employees'), ['file' => $this->xlsx([
            ['Kode', 'Nama', 'Pola Kerja', 'Tanggal Masuk', 'Telepon'],
            ['KRY-101', 'Siti', 'Per pcs jadi', '01/03/2024', '0812555'],
            ['KRY-102', 'Dewi', '', 45383, ''],
            ['KRY-103', 'Rina', 'keduanya', '', ''],
        ])])->assertSessionHas('success');

        $this->assertSame(EmployeeWorkType::Full, Employee::where('code', 'KRY-101')->first()->work_type);
        $this->assertSame('2024-03-01', Employee::where('code', 'KRY-101')->first()->join_date->toDateString());
        $this->assertSame(EmployeeWorkType::Operation, Employee::where('code', 'KRY-102')->first()->work_type);
        $this->assertSame('2024-04-01', Employee::where('code', 'KRY-102')->first()->join_date->toDateString());
        $this->assertSame(EmployeeWorkType::Mixed, Employee::where('code', 'KRY-103')->first()->work_type);
    }

    public function test_opening_stock_import_values_inventory_and_journals_opening_equity(): void
    {
        $online = Warehouse::create(['code' => 'OL', 'name' => 'Gudang Online', 'is_active' => true]);
        $hoodie = Product::create(['code' => 'HD', 'name' => 'Hoodie', 'unit_id' => Unit::where('code', 'PCS')->value('id'), 'price' => 150000]);
        $variant = $hoodie->variants()->create(['sku' => 'HD-L', 'size' => 'L']);

        // Barang jadi tanpa harga pokok wajib diisi harganya; gudang harus terdaftar
        $this->post(route('imports.store', 'opening-stock'), ['file' => $this->xlsx([
            ['Jenis', 'Kode / SKU', 'Gudang', 'Jumlah', 'Harga Pokok per Unit'],
            ['Produk', 'HD-L', '', 10, ''],
            ['', 'TIDAK-ADA', 'Gudang Mars', 0, ''],
        ])])->assertSessionHasErrors('file');
        $errors = session('errors')->get('file');
        $this->assertContains('Baris 2: barang jadi HD-L belum punya harga pokok, isi kolom "Harga Pokok per Unit".', $errors);
        $this->assertContains('Baris 3: kolom "Jumlah" harus lebih dari 0.', $errors);
        $this->assertContains('Baris 3: kode / SKU "TIDAK-ADA" belum terdaftar di bahan baku maupun produk.', $errors);
        $this->assertContains('Baris 3: gudang "Gudang Mars" tidak ditemukan.', $errors);
        $this->assertSame(0.0, (float) $variant->fresh()->stock);

        $this->post(route('imports.store', 'opening-stock'), ['file' => $this->xlsx([
            ['Jenis', 'Kode / SKU', 'Gudang', 'Jumlah', 'Harga Pokok per Unit', 'Tanggal'],
            ['Produk', 'HD-L', 'OL', 10, 60000, '01/10/2026'],
            ['', 'HD-L', '', 5, 66000, '01/10/2026'],
        ])])->assertSessionHas('success');

        $variant->refresh();
        $this->assertSame(15.0, (float) $variant->stock);
        $this->assertSame(10.0, $variant->stockIn($online));
        $this->assertSame(62000.0, (float) $variant->average_cost);

        $ledger = app(LedgerAccounts::class);
        $finished = (float) JournalLine::where('account_id', $ledger->id('finished_goods'))->sum('debit');
        $this->assertSame(930000.0, $finished);
        $this->assertSame(600000.0, (float) JournalLine::where('account_id', $ledger->id('opening_equity'))->sum('credit'), 'Mutasi pertama = saldo awal');
    }

    public function test_journal_import_creates_balanced_manual_journals(): void
    {
        $header = ['Tanggal', 'Keterangan Jurnal', 'No. Referensi', 'Kode Akun', 'Keterangan Baris', 'Debit', 'Kredit'];

        $this->post(route('imports.store', 'journals'), ['file' => $this->xlsx([
            $header,
            ['30/09/2026', 'Saldo awal', 'SA', '1101', 'Kas kecil', 2000000, ''],
            ['30/09/2026', 'Saldo awal', 'SA', '1102', 'BCA', 8000000, ''],
            ['30/09/2026', 'Saldo awal', 'SA', '3901', '', '', 9000000],
            ['30/09/2026', 'Setoran', '', '1000', '', 5, 5],
        ])])->assertSessionHasErrors('file');
        $errors = session('errors')->get('file');
        $this->assertContains('Baris 5: akun 1000 adalah akun induk / nonaktif, pilih akun rinciannya.', $errors);
        $this->assertContains('Baris 5: isi Debit atau Kredit (salah satu, lebih dari 0).', $errors);

        $this->post(route('imports.store', 'journals'), ['file' => $this->xlsx([
            $header,
            ['30/09/2026', 'Saldo awal', 'SA', '1101', 'Kas kecil', 2000000, ''],
            ['30/09/2026', 'Saldo awal', 'SA', '1102', 'BCA', 8000000, ''],
            ['30/09/2026', 'Saldo awal', 'SA', '3901', '', '', 9000000],
        ])])->assertSessionHasErrors('file');
        $this->assertStringContainsString('Baris 2, 3, 4: jurnal "Saldo awal" tidak seimbang', session('errors')->get('file')[1]);

        $this->post(route('imports.store', 'journals'), ['file' => $this->xlsx([
            $header,
            ['30/09/2026', 'Saldo awal', 'SA', '1101', 'Kas kecil', 2000000, ''],
            ['30/09/2026', 'Saldo awal', 'SA', '1102', 'BCA', 8000000, ''],
            ['30/09/2026', 'Saldo awal', 'SA', '3901', '', '', 10000000],
            ['01/10/2026', 'Modal tambahan', '', '1102', '', 1000000, ''],
            ['01/10/2026', 'Modal tambahan', '', '3101', '', '', 1000000],
        ])])->assertSessionHas('success', 'Impor Jurnal / Saldo Awal Akun selesai: 2 data baru, 0 diperbarui.');

        $entry = JournalEntry::where('description', 'Saldo awal')->firstOrFail();
        $this->assertSame(JournalType::Manual, $entry->type);
        $this->assertSame('2026-09-30', $entry->date->toDateString());
        $this->assertSame(10000000.0, (float) $entry->lines()->sum('debit'));
    }

    public function test_templates_download_and_round_trip_current_data(): void
    {
        foreach (array_keys(ImportRegistry::types()) as $type) {
            $response = $this->get(route('imports.template', $type))->assertOk()
                ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $rows = SpreadsheetReader::read($response->baseResponse->getFile()->getPathname(), 'template.xlsx');
            $this->assertCount(1, $rows, "Template {$type} hanya berisi judul kolom");
            $this->get(route('imports.show', $type))->assertOk();
        }

        // Unduh data saat ini lalu unggah ulang tanpa perubahan = semua diperbarui, tidak ada yang baru
        foreach (['customers', 'suppliers', 'materials', 'products'] as $type) {
            $response = $this->get(route('imports.template', [$type, 'data' => 1]))->assertOk();
            $path = $response->baseResponse->getFile()->getPathname();
            copy($path, $copy = $path.'.xlsx');

            $this->post(route('imports.store', $type), ['file' => new UploadedFile($copy, "data-{$type}.xlsx", null, null, true)])
                ->assertSessionHas('success', fn ($message) => str_contains($message, ': 0 data baru'));
        }
    }

    public function test_import_respects_permissions(): void
    {
        $this->actingAs(User::factory()->role(Role::Sales)->create());

        $this->get(route('imports.index'))->assertOk()->assertSee(route('imports.show', 'customers'))->assertDontSee(route('imports.show', 'opening-stock'));
        $this->get(route('imports.show', 'customers'))->assertOk();
        $this->get(route('imports.show', 'opening-stock'))->assertForbidden();
        $this->get(route('imports.show', 'unknown'))->assertNotFound();

        $this->actingAs(User::factory()->role(Role::Keuangan)->create());
        $this->get(route('imports.index'))->assertOk()->assertSee(route('imports.show', 'journals'));

        $this->actingAs(User::factory()->role(Role::Gudang)->create());
        $this->get(route('imports.show', 'opening-stock'))->assertOk();
        $this->get(route('imports.show', 'journals'))->assertForbidden();
    }

    public function test_stock_adjustment_accepts_unit_cost(): void
    {
        $variant = ProductVariant::where('sku', 'TS-BSC-HTM-M')->firstOrFail();
        $before = (float) $variant->stock;

        $this->post(route('stock.adjustment.store'), [
            'item' => 'variant:'.$variant->id, 'date' => '2026-10-28', 'mode' => 'in', 'quantity' => 10, 'unit_cost' => 45000, 'notes' => 'Saldo awal',
        ])->assertRedirect();

        $variant->refresh();
        $this->assertSame($before + 10, (float) $variant->stock);
        $this->assertSame(45000.0, (float) $variant->stockMovements()->latest('id')->first()->unit_cost);
    }
}
