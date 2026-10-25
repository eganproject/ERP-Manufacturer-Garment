<?php

namespace App\Http\Controllers;

use App\Enums\CashTransactionType;
use App\Enums\PaymentStatus;
use App\Models\Account;
use App\Models\CashTransaction;
use App\Services\Accounting\FinancialReports;
use App\Services\CashBankService;
use App\Support\DocumentPdf;
use App\Support\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Kas & bank: saldo per rekening, mutasi rekening, bukti kas masuk/keluar & transfer antar rekening.
 */
class CashBankController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:cashbank.view', only: ['index', 'statement', 'transactions', 'show', 'pdf']),
            new Middleware('can:cashbank.manage', only: ['create', 'store', 'cancel']),
        ];
    }

    public function index(FinancialReports $reports)
    {
        $sums = $reports->sums(null, today());
        $monthStart = today()->startOfMonth();
        $month = $reports->sums($monthStart, today());

        $accounts = Account::cash()->orderBy('code')->get()->map(function (Account $account) use ($sums, $month) {
            $sum = $sums->get($account->id, ['debit' => 0, 'credit' => 0]);
            $mov = $month->get($account->id, ['debit' => 0, 'credit' => 0]);

            return [
                'account' => $account,
                'balance' => round($sum['debit'] - $sum['credit'], 2),
                'inflow' => $mov['debit'],
                'outflow' => $mov['credit'],
            ];
        })->filter(fn ($row) => $row['account']->is_active || abs($row['balance']) >= 0.005)->values();

        $recent = CashTransaction::with(['cashAccount', 'toAccount'])->latest('date')->latest('id')->limit(10)->get();

        return view('cash-bank.index', [
            'accounts' => $accounts,
            'total' => round($accounts->sum('balance'), 2),
            'recent' => $recent,
            'cashParent' => Account::where('code', '1100')->where('is_header', true)->value('id'),
        ]);
    }

    /** Mutasi satu rekening (rekening koran) dengan saldo berjalan. */
    public function statement(Request $request, Account $account, FinancialReports $reports)
    {
        abort_unless($account->is_cash, 404);
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'export' => ['nullable', Rule::in(['xlsx', 'pdf'])],
        ]);
        $from = $request->from ? Carbon::parse($request->from) : today()->startOfMonth();
        $to = $request->to ? Carbon::parse($request->to) : today();
        $data = $reports->ledger($account, $from, $to);

        if ($response = ReportExport::respond($reports->ledgerTable($account, $data, $from, $to), $request->query('export'))) {
            return $response;
        }

        return view('cash-bank.statement', [
            'account' => $account,
            'data' => $data,
            'from' => $from,
            'to' => $to,
            'options' => Account::cash()->orderBy('code')->get()->mapWithKeys(fn ($a) => [$a->id => $a->display_name]),
        ]);
    }

    public function transactions(Request $request)
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'type' => ['nullable', Rule::enum(CashTransactionType::class)],
            'status' => ['nullable', Rule::enum(PaymentStatus::class)],
            'account_id' => ['nullable', 'integer'],
        ]);
        $from = $request->from ? Carbon::parse($request->from) : today()->startOfMonth();
        $to = $request->to ? Carbon::parse($request->to) : today();

        $query = CashTransaction::query()
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->when($request->type, fn ($q, $type) => $q->where('type', $type))
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->when($request->account_id, fn ($q, $id) => $q->where(fn ($q) => $q->where('cash_account_id', $id)->orWhere('to_account_id', $id)))
            ->when($request->q, fn ($q, $search) => $q->where(fn ($q) => $q
                ->where('number', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhere('party_name', 'like', "%{$search}%")
                ->orWhere('reference', 'like', "%{$search}%")));

        $totals = (clone $query)->where('status', PaymentStatus::Posted)
            ->selectRaw('type, COALESCE(SUM(amount), 0) AS total')->groupBy('type')->pluck('total', 'type');

        return view('cash-bank.transactions', [
            'transactions' => $query->with(['cashAccount', 'toAccount'])->orderByDesc('date')->orderByDesc('id')->paginate(25)->withQueryString(),
            'totals' => $totals,
            'from' => $from,
            'to' => $to,
            'cashAccounts' => Account::cash()->orderBy('code')->get()->mapWithKeys(fn ($a) => [$a->id => $a->display_name]),
        ]);
    }

    public function create(Request $request)
    {
        $type = CashTransactionType::tryFrom((string) $request->query('type')) ?? CashTransactionType::Out;

        return view('cash-bank.create', [
            'type' => $type,
            'cashAccounts' => Account::cashOptions(),
            'accounts' => Account::postable()->where('is_cash', false)->orderBy('code')->get()->mapWithKeys(fn ($a) => [$a->id => $a->display_name]),
            'defaultAccount' => $request->integer('account_id') ?: null,
        ]);
    }

    public function store(Request $request, CashBankService $service)
    {
        $type = CashTransactionType::from($request->validate(['type' => ['required', Rule::enum(CashTransactionType::class)]])['type']);
        $transfer = $type === CashTransactionType::Transfer;

        $data = $request->validate([
            'date' => ['required', 'date'],
            'cash_account_id' => ['required', Account::cashRule()],
            'to_account_id' => [$transfer ? 'required' : 'nullable', Account::cashRule(), 'different:cash_account_id'],
            'amount' => [$transfer ? 'required' : 'nullable', 'numeric', 'min:0'],
            'party_name' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            'description' => ['required', 'string', 'max:255'],
            'lines' => [$transfer ? 'nullable' : 'required', 'array'],
            'lines.*.account_id' => ['nullable', 'integer'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.amount' => ['nullable', 'numeric', 'min:0'],
        ], [
            'to_account_id.different' => 'Rekening tujuan harus berbeda dari rekening asal.',
        ], [
            'cash_account_id' => $transfer ? 'rekening asal' : 'rekening kas/bank',
            'to_account_id' => 'rekening tujuan',
            'amount' => 'jumlah',
            'description' => 'keterangan',
            'party_name' => $type === CashTransactionType::In ? 'diterima dari' : 'dibayar ke',
        ]);

        $transaction = $service->record($type, $data, $data['lines'] ?? []);

        return redirect()->route('cash-transactions.show', $transaction)
            ->with('success', "{$type->label()} {$transaction->number} tersimpan sebesar ".rupiah($transaction->amount).'.');
    }

    public function show(CashTransaction $cashTransaction)
    {
        $cashTransaction->load(['cashAccount', 'toAccount', 'lines.account', 'creator', 'activities.user']);

        return view('cash-bank.show', ['transaction' => $cashTransaction]);
    }

    public function cancel(Request $request, CashTransaction $cashTransaction, CashBankService $service)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'alasan pembatalan']);
        $service->cancel($cashTransaction, $data['reason']);

        return back()->with('success', "{$cashTransaction->type->label()} {$cashTransaction->number} dibatalkan dan jurnalnya dibalik.");
    }

    public function pdf(CashTransaction $cashTransaction)
    {
        $cashTransaction->load(['cashAccount', 'toAccount', 'lines.account', 'creator']);

        return DocumentPdf::stream('pdf.cash-transaction', ['transaction' => $cashTransaction], $cashTransaction->number);
    }
}
