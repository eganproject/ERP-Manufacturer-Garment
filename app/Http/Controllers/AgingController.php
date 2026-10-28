<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Supplier;
use App\Services\AgingReport;
use App\Support\ReportExport;
use App\Support\ReportTable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Laporan umur piutang (AR aging) dan umur hutang (AP aging).
 */
class AgingController extends Controller
{
    public function receivables(Request $request, AgingReport $report)
    {
        return $this->render($request, $report, 'receivables');
    }

    public function payables(Request $request, AgingReport $report)
    {
        return $this->render($request, $report, 'payables');
    }

    private function render(Request $request, AgingReport $report, string $kind)
    {
        $request->validate(['as_of' => ['nullable', 'date'], 'party_id' => ['nullable', 'integer']]);
        $asOf = $request->as_of ? Carbon::parse($request->as_of) : today();
        $partyId = $request->integer('party_id') ?: null;
        $data = $report->build($kind, $asOf, $partyId);

        if ($export = ReportExport::respond($this->table($kind, $asOf, $data), $request->query('export'))) {
            return $export;
        }

        return view('reports.aging', $data + [
            'kind' => $kind,
            'asOf' => $asOf,
            'partyId' => $partyId,
            'parties' => ($kind === 'receivables' ? Customer::query() : Supplier::query())->orderBy('name')->pluck('name', 'id'),
            'buckets' => AgingReport::BUCKETS,
        ]);
    }

    /** Rincian per dokumen (invoice / tagihan) untuk ekspor Excel & PDF. */
    private function table(string $kind, Carbon $asOf, array $data): ReportTable
    {
        $isAr = $kind === 'receivables';
        $relation = $isAr ? 'customer' : 'supplier';
        $columns = [
            'party' => [$isAr ? 'Pelanggan' : 'Supplier', 'text'],
            'number' => [$isAr ? 'No. Invoice' : 'No. Tagihan', 'text'],
            'date' => ['Tanggal', 'date'],
            'due' => ['Jatuh Tempo', 'date'],
            'age' => ['Umur (hari)', 'number'],
        ];
        foreach (AgingReport::BUCKETS as $key => $label) {
            $columns[$key] = [$label, 'money'];
        }
        $columns['total'] = ['Sisa', 'money'];

        $rows = $data['documents']->sortBy(fn ($doc) => [$doc->{$relation}?->name, $doc->due_date])->map(function ($doc) use ($isAr, $relation) {
            $row = [
                'party' => $doc->{$relation}?->name,
                'number' => $doc->number,
                'date' => $isAr ? $doc->invoice_date : $doc->bill_date,
                'due' => $doc->due_date,
                'age' => max(0, $doc->age),
            ];
            foreach (array_keys(AgingReport::BUCKETS) as $bucket) {
                $row[$bucket] = $doc->bucket === $bucket ? $doc->balance : null;
            }

            return $row + ['total' => $doc->balance];
        });

        return new ReportTable(
            $isAr ? 'Umur Piutang' : 'Umur Hutang',
            'Per '.tanggal($asOf),
            $columns,
            $rows,
            ['party' => 'TOTAL'] + $data['totals'],
            orientation: 'landscape',
        );
    }
}
