<?php

namespace Tests\Feature;

use App\Models\Bill;
use App\Models\DefectHandling;
use App\Models\EmployeeAdvance;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\MarketplaceSettlement;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\PieceworkSheet;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\Setting;
use App\Models\User;
use App\Support\DocumentPdf;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrintSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_settings_save_signers_footer_and_purchase_terms(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->actingAs(User::where('email', 'admin@erp.test')->firstOrFail());

        $this->put(route('settings.update'), [
            'company_name' => 'CV Garmen Uji',
            'company_website' => 'www.garmenuji.test',
            'document_footer' => 'Terima kasih atas kepercayaan Anda.',
            'purchase_terms' => 'Pembayaran 30 hari setelah tagihan diterima.',
            'signers' => [
                'director' => ['name' => 'Budi Santoso', 'title' => 'Direktur Utama'],
                'warehouse' => ['name' => 'Sari', 'title' => ''],
            ],
        ])->assertSessionHasNoErrors();

        $signers = Setting::signers();
        $this->assertSame('Budi Santoso', $signers['director']['name']);
        $this->assertSame('Direktur Utama', $signers['director']['title']);
        $this->assertSame('Kepala Gudang', $signers['warehouse']['title'], 'jabatan kosong memakai default');
        $this->assertNull($signers['finance']['name']);
        $this->assertSame('www.garmenuji.test', Setting::company()['website']);
        $this->assertSame('Pembayaran 30 hari setelah tagihan diterima.', Setting::purchaseTerms());

        $this->get(route('settings.edit'))->assertOk()->assertSee('Budi Santoso')->assertSee('Catatan Kaki Semua Dokumen');

        // Kop, catatan kaki & syarat PO ikut tercetak; penyetuju yang tercatat di dokumen tetap dipakai
        $po = PurchaseOrder::with(['supplier', 'items.material.unit', 'creator', 'approver'])->whereNotNull('approved_by')->firstOrFail();
        $html = view('pdf.purchase-order', ['order' => $po, 'company' => DocumentPdf::company()])->render();
        $this->assertStringContainsString('CV Garmen Uji', $html);
        $this->assertStringContainsString('www.garmenuji.test', $html);
        $this->assertStringContainsString('Terima kasih atas kepercayaan Anda.', $html);
        $this->assertStringContainsString('Pembayaran 30 hari setelah tagihan diterima.', $html);
        $this->assertStringContainsString($po->approver->name, $html);
        $this->assertStringNotContainsString('Direktur Utama', $html);

        // Kolom "Disetujui oleh" SO yang kosong diisi pimpinan dari pengaturan
        $so = SalesOrder::with(['customer', 'items.variant.product.unit', 'creator'])->firstOrFail();
        $html = view('pdf.sales-order', ['order' => $so, 'company' => DocumentPdf::company()])->render();
        $this->assertStringContainsString('Budi Santoso', $html);
        $this->assertStringContainsString('Direktur Utama', $html);
    }

    public function test_every_printable_document_renders_as_pdf(): void
    {
        $this->seed(DatabaseSeeder::class); // termasuk data demo satu siklus (FlowDemoSeeder)

        Setting::put(['invoice_bank_info' => "BCA 123-456 a.n. CV Uji\nBRI 987-654", 'signer_finance_name' => 'Rina', 'document_footer' => 'Catatan kaki uji']);
        $this->actingAs(User::where('email', 'admin@erp.test')->firstOrFail());

        $urls = [
            route('bills.pdf', Bill::firstOrFail()),
            route('journals.pdf', JournalEntry::firstOrFail()),
            route('piecework.pdf', PieceworkSheet::firstOrFail()),
            route('employee-advances.pdf', EmployeeAdvance::firstOrFail()),
            route('defects.pdf', DefectHandling::firstOrFail()),
            route('invoices.pdf', Invoice::firstOrFail()),
            route('payments.pdf', Payment::firstOrFail()),
            route('sales-orders.pdf', SalesOrder::firstOrFail()),
            route('purchase-orders.pdf', PurchaseOrder::firstOrFail()),
            route('payrolls.pdf', Payroll::firstOrFail()),
        ];
        if ($quotation = Quotation::first()) {
            $urls[] = route('quotations.pdf', $quotation);
        }
        if ($settlement = MarketplaceSettlement::first()) {
            $urls[] = route('marketplace-settlements.pdf', $settlement);
        }

        foreach ($urls as $url) {
            $response = $this->get($url);
            $response->assertOk()->assertHeader('content-type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $response->getContent(), $url);
        }

        // Tombol cetak tersedia di halaman dokumen
        $this->get(route('bills.show', Bill::first()))->assertSee(route('bills.pdf', Bill::first()));
        $this->get(route('journals.show', JournalEntry::first()))->assertSee(route('journals.pdf', JournalEntry::first()));

        // Rekening bank & penanda tangan keuangan tercetak di invoice
        $invoice = Invoice::with(['customer', 'salesOrder.deliveries', 'items.variant.product.unit', 'creator'])->first();
        $html = view('pdf.invoice', ['invoice' => $invoice, 'settings' => Setting::invoice(), 'company' => DocumentPdf::company()])->render();
        $this->assertStringContainsString('BRI 987-654', $html);
        $this->assertStringContainsString('Catatan kaki uji', $html);
        $this->assertStringContainsString('Keuangan', $html);
    }
}
