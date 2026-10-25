<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\Accounting\CashFlowReport;
use App\Services\Accounting\FinancialReports;
use App\Support\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Laporan keuangan: buku besar, neraca saldo, laba rugi, neraca (tampilan + ekspor Excel/PDF).
 */
class FinancialReportController extends Controller
{
    public function ledger(Request $request, FinancialReports $reports)
    {
        $request->validate($this->rules() + ['account_id' => ['nullable', 'integer', 'exists:accounts,id']]);
        [$from, $to] = $this->period($request);

        $options = Account::where('is_header', false)->orderBy('code')->get()->mapWithKeys(fn ($a) => [$a->id => $a->display_name]);
        $account = Account::where('is_header', false)->find($request->integer('account_id') ?: Account::where('is_header', false)->orderBy('code')->value('id'));
        $data = $account ? $reports->ledger($account, $from, $to) : null;

        if ($account && $response = ReportExport::respond($reports->ledgerTable($account, $data, $from, $to), $request->query('export'))) {
            return $response;
        }

        return view('accounting.reports.ledger', compact('account', 'options', 'data', 'from', 'to'));
    }

    public function trialBalance(Request $request, FinancialReports $reports)
    {
        $request->validate($this->rules());
        [$from, $to] = $this->period($request);
        $data = $reports->trialBalance($from, $to, $request->boolean('include_zero'));

        if ($response = ReportExport::respond($reports->trialBalanceTable($data, $from, $to), $request->query('export'))) {
            return $response;
        }

        return view('accounting.reports.trial-balance', $data + compact('from', 'to'));
    }

    public function profitLoss(Request $request, FinancialReports $reports)
    {
        $request->validate($this->rules());
        [$from, $to] = $this->period($request);
        $data = $reports->profitLoss($from, $to);

        if ($response = ReportExport::respond($reports->profitLossTable($data, $from, $to), $request->query('export'))) {
            return $response;
        }

        // Pembanding: periode sebelumnya dengan panjang yang sama
        $days = $from->diffInDays($to) + 1;
        $previous = $reports->profitLoss($from->copy()->subDays($days), $from->copy()->subDay());

        return view('accounting.reports.profit-loss', $data + compact('from', 'to', 'previous'));
    }

    public function balanceSheet(Request $request, FinancialReports $reports)
    {
        $request->validate(['date' => ['nullable', 'date'], 'export' => ['nullable', Rule::in(['xlsx', 'pdf'])]]);
        $asOf = $request->date ? Carbon::parse($request->date) : today();
        $data = $reports->balanceSheet($asOf);

        if ($response = ReportExport::respond($reports->balanceSheetTable($data, $asOf), $request->query('export'))) {
            return $response;
        }

        return view('accounting.reports.balance-sheet', $data + compact('asOf'));
    }

    public function cashFlow(Request $request, CashFlowReport $report)
    {
        $request->validate($this->rules() + ['method' => ['nullable', Rule::in(['direct', 'indirect'])]]);
        [$from, $to] = $this->period($request);
        $method = $request->query('method', 'direct');
        $data = $report->build($from, $to, $method);

        if ($response = ReportExport::respond($report->table($data, $from, $to), $request->query('export'))) {
            return $response;
        }

        return view('accounting.reports.cash-flow', $data + compact('from', 'to'));
    }

    private function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'export' => ['nullable', Rule::in(['xlsx', 'pdf'])],
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function period(Request $request): array
    {
        return [
            $request->from ? Carbon::parse($request->from) : today()->startOfMonth(),
            $request->to ? Carbon::parse($request->to) : today(),
        ];
    }
}
