<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Supplier;
use App\Services\AgingReport;
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

        return view('reports.aging', $report->build($kind, $asOf, $partyId) + [
            'kind' => $kind,
            'asOf' => $asOf,
            'partyId' => $partyId,
            'parties' => ($kind === 'receivables' ? Customer::query() : Supplier::query())->orderBy('name')->pluck('name', 'id'),
            'buckets' => AgingReport::BUCKETS,
        ]);
    }
}
