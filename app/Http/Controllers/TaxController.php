<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\Invoice;
use App\Models\VatPeriod;
use App\Services\Reports\VatReport;
use App\Services\VatService;
use App\Support\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Laporan PPN per masa, penutupan masa PPN (PPN-), dan nomor faktur pajak.
 */
class TaxController extends Controller
{
    public function report(Request $request, VatReport $report)
    {
        $request->validate([
            'period' => ['nullable', 'date_format:Y-m'],
            'export' => ['nullable', Rule::in(['xlsx', 'pdf'])],
        ]);

        $period = $request->period
            ? Carbon::createFromFormat('Y-m', $request->period)->startOfMonth()
            : today()->startOfMonth()->subMonthNoOverflow();
        $data = $report->build($period);

        if ($response = ReportExport::respond($report->table($data), $request->query('export'))) {
            return $response;
        }

        return view('reports.vat', $data + [
            'periods' => VatPeriod::with('creator')->latest('period')->latest('id')->paginate(12)->withQueryString(),
        ]);
    }

    public function close(Request $request, VatService $service)
    {
        $data = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['period' => 'masa pajak']);

        $vat = $service->close(Carbon::createFromFormat('Y-m', $data['period'])->startOfMonth(), $data['notes'] ?? null);
        $result = $vat->net >= 0 ? 'kurang bayar '.rupiah($vat->payable()).' (setor lewat Kas Keluar ke akun Hutang PPN)' : 'lebih bayar '.rupiah($vat->overpaid()).' dikompensasikan ke masa berikutnya';

        return redirect()->route('reports.vat', ['period' => $vat->period->format('Y-m')])
            ->with('success', "Masa PPN {$vat->periodLabel()} ditutup ({$vat->number}): {$result}.");
    }

    public function cancel(Request $request, VatPeriod $vatPeriod, VatService $service)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'alasan pembatalan']);
        $service->cancel($vatPeriod, $data['reason']);

        return back()->with('success', "Penutupan masa PPN {$vatPeriod->periodLabel()} dibatalkan dan jurnalnya dibalik.");
    }

    public function invoiceNumber(Request $request, Invoice $invoice, VatService $service)
    {
        $service->setTaxInvoice($invoice, ...$this->numberInput($request));

        return back()->with('success', 'Nomor faktur pajak keluaran disimpan.');
    }

    public function billNumber(Request $request, Bill $bill, VatService $service)
    {
        $service->setTaxInvoice($bill, ...$this->numberInput($request));

        return back()->with('success', 'Nomor faktur pajak masukan disimpan.');
    }

    /** @return array{0: string|null, 1: string|null} */
    private function numberInput(Request $request): array
    {
        $data = $request->validate([
            'tax_invoice_number' => ['nullable', 'string', 'max:30'],
            'tax_invoice_date' => ['nullable', 'date'],
        ], [], ['tax_invoice_number' => 'no. faktur pajak', 'tax_invoice_date' => 'tanggal faktur pajak']);

        return [$data['tax_invoice_number'] ?? null, $data['tax_invoice_date'] ?? null];
    }
}
