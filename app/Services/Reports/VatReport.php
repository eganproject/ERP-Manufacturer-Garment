<?php

namespace App\Services\Reports;

use App\Enums\AssetAcquisitionType;
use App\Enums\FixedAssetStatus;
use App\Enums\InvoiceStatus;
use App\Enums\ReturnStatus;
use App\Models\Bill;
use App\Models\FixedAsset;
use App\Models\Invoice;
use App\Models\PurchaseReturn;
use App\Models\SalesReturn;
use App\Models\VatPeriod;
use App\Services\VatService;
use App\Support\ReportTable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Laporan PPN per masa pajak (bulan kalender).
 *
 * - PPN keluaran: invoice ber-PPN (tidak dibatalkan) dikurangi nota kredit retur penjualan ber-PPN.
 * - PPN masukan : tagihan supplier ber-PPN + aset tetap tunai ber-PPN dikurangi nota debit retur pembelian.
 * Kurang (+) / lebih (−) bayar = keluaran − masukan − kompensasi lebih bayar masa sebelumnya.
 */
class VatReport
{
    public function __construct(private VatService $service) {}

    /**
     * @return array{period: Carbon, output: Collection, input: Collection, totals: array<string, float|int>, closed: VatPeriod|null, previous: VatPeriod|null, canClose: string|null}
     */
    public function build(Carbon $period): array
    {
        $period = $period->copy()->startOfMonth();
        [$from, $to] = [$period->toDateString(), $period->copy()->endOfMonth()->format('Y-m-d 23:59:59')];

        $output = $this->output($from, $to);
        $input = $this->input($from, $to);
        $closed = VatPeriod::posted()->whereDate('period', $period)->latest('id')->first();
        $compensation = $closed ? (float) $closed->compensation : $this->service->compensationFor($period);

        $outputTax = round($output->sum('tax'), 2);
        $inputTax = round($input->sum('tax'), 2);
        $net = round($outputTax - $inputTax - $compensation, 2);

        return [
            'period' => $period,
            'output' => $output,
            'input' => $input,
            'totals' => [
                'output_dpp' => round($output->sum('dpp'), 2),
                'output' => $outputTax,
                'input_dpp' => round($input->sum('dpp'), 2),
                'input' => $inputTax,
                'compensation' => $compensation,
                'net' => $net,
                'missing_output' => $output->where('kind', 'invoice')->whereNull('tax_invoice_number')->count(),
                'missing_input' => $input->whereIn('kind', ['bill', 'asset'])->whereNull('tax_invoice_number')->count(),
                // Selisih bila dokumen di masa yang sudah ditutup berubah (perlu pembetulan)
                'difference' => $closed ? round($net - (float) $closed->net, 2) : 0,
            ],
            'closed' => $closed,
            'previous' => VatPeriod::posted()->whereDate('period', '<', $period)->orderByDesc('period')->first(),
            'canClose' => $closed ? null : $this->service->closeBlocker($period),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function output(string $from, string $to): Collection
    {
        $invoices = Invoice::with('customer')
            ->where('status', '!=', InvoiceStatus::Cancelled)
            ->where('tax_amount', '!=', 0)
            ->whereBetween('invoice_date', [$from, $to])
            ->get()
            ->map(fn (Invoice $i) => [
                'kind' => 'invoice',
                'type' => 'Invoice',
                'id' => $i->id,
                'date' => $i->invoice_date,
                'number' => $i->number,
                'tax_invoice_number' => $i->tax_invoice_number,
                'tax_invoice_date' => $i->tax_invoice_date ?? $i->invoice_date,
                'party' => $i->customer->name,
                'npwp' => $i->customer->tax_number,
                'dpp' => $i->netSales(),
                'tax' => (float) $i->tax_amount,
            ]);

        $returns = SalesReturn::with('customer', 'salesOrder')
            ->where('status', ReturnStatus::Posted)
            ->where('tax_amount', '!=', 0)
            ->whereBetween('return_date', [$from, $to])
            ->get()
            ->map(fn (SalesReturn $r) => [
                'kind' => 'sales_return',
                'type' => 'Retur penjualan',
                'id' => $r->id,
                'date' => $r->return_date,
                'number' => $r->number,
                'tax_invoice_number' => null,
                'tax_invoice_date' => null,
                'party' => $r->customer->name,
                'npwp' => $r->customer->tax_number,
                'dpp' => -(float) $r->amount,
                'tax' => -(float) $r->tax_amount,
            ]);

        return $this->sort($invoices->concat($returns));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function input(string $from, string $to): Collection
    {
        $bills = Bill::with('supplier')
            ->where('status', '!=', InvoiceStatus::Cancelled)
            ->where('tax_amount', '!=', 0)
            ->whereBetween('bill_date', [$from, $to])
            ->get()
            ->map(fn (Bill $b) => [
                'kind' => 'bill',
                'type' => 'Tagihan supplier',
                'id' => $b->id,
                'date' => $b->bill_date,
                'number' => $b->number,
                'tax_invoice_number' => $b->tax_invoice_number,
                'tax_invoice_date' => $b->tax_invoice_date ?? $b->bill_date,
                'party' => $b->supplier->name,
                'npwp' => $b->supplier->tax_number,
                'dpp' => round((float) $b->subtotal - (float) $b->discount, 2),
                'tax' => (float) $b->tax_amount,
            ]);

        $assets = FixedAsset::with('supplier')
            ->where('acquisition_type', AssetAcquisitionType::Cash)
            ->where('status', '!=', FixedAssetStatus::Cancelled)
            ->where('tax_amount', '!=', 0)
            ->whereBetween('acquisition_date', [$from, $to])
            ->get()
            ->map(fn (FixedAsset $a) => [
                'kind' => 'asset',
                'type' => 'Aset tetap (tunai)',
                'id' => $a->id,
                'date' => $a->acquisition_date,
                'number' => $a->number,
                'tax_invoice_number' => $a->tax_invoice_number,
                'tax_invoice_date' => $a->acquisition_date,
                'party' => $a->supplier?->name ?? $a->name,
                'npwp' => $a->supplier?->tax_number,
                'dpp' => (float) $a->cost,
                'tax' => (float) $a->tax_amount,
            ]);

        $returns = PurchaseReturn::with('supplier')
            ->where('status', ReturnStatus::Posted)
            ->where('tax_amount', '!=', 0)
            ->whereBetween('return_date', [$from, $to])
            ->get()
            ->map(fn (PurchaseReturn $r) => [
                'kind' => 'purchase_return',
                'type' => 'Retur pembelian',
                'id' => $r->id,
                'date' => $r->return_date,
                'number' => $r->number,
                'tax_invoice_number' => null,
                'tax_invoice_date' => null,
                'party' => $r->supplier->name,
                'npwp' => $r->supplier->tax_number,
                'dpp' => -(float) $r->amount,
                'tax' => -(float) $r->tax_amount,
            ]);

        return $this->sort($bills->concat($assets)->concat($returns));
    }

    private function sort(Collection $rows): Collection
    {
        return $rows->sortBy(fn ($r) => $r['date']->format('Ymd').$r['number'])->values();
    }

    public function table(array $data): ReportTable
    {
        $rows = collect();

        foreach (['output' => 'Keluaran', 'input' => 'Masukan'] as $key => $label) {
            foreach ($data[$key] as $row) {
                $rows->push([
                    'section' => $label,
                    'date' => $row['date'],
                    'type' => $row['type'],
                    'number' => $row['number'],
                    'tax_invoice_number' => $row['tax_invoice_number'],
                    'tax_invoice_date' => $row['tax_invoice_date'],
                    'party' => $row['party'],
                    'npwp' => $row['npwp'],
                    'dpp' => $row['dpp'],
                    'tax' => $row['tax'],
                ]);
            }
        }

        $t = $data['totals'];

        return new ReportTable(
            title: 'Laporan PPN',
            subtitle: 'Masa '.$data['period']->translatedFormat('F Y'),
            columns: [
                'section' => ['PPN', 'text'],
                'date' => ['Tanggal', 'date'],
                'type' => ['Jenis Dokumen', 'text'],
                'number' => ['No. Dokumen', 'text'],
                'tax_invoice_number' => ['No. Faktur Pajak', 'text'],
                'tax_invoice_date' => ['Tgl Faktur', 'date'],
                'party' => ['Lawan Transaksi', 'text'],
                'npwp' => ['NPWP', 'text'],
                'dpp' => ['DPP', 'money'],
                'tax' => ['PPN', 'money'],
            ],
            rows: $rows,
            summary: [
                ['PPN Keluaran', rupiah($t['output'])],
                ['PPN Masukan', rupiah($t['input'])],
                ['Kompensasi lebih bayar masa sebelumnya', rupiah($t['compensation'])],
                [$t['net'] >= 0 ? 'Kurang bayar (disetor)' : 'Lebih bayar (dikompensasikan)', rupiah(abs($t['net']))],
            ],
            orientation: 'landscape',
        );
    }
}
