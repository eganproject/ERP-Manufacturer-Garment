<?php

namespace App\Services\Accounting;

use App\Enums\AccountType;
use App\Enums\CashFlowActivity;
use App\Models\Account;
use App\Models\JournalLine;
use App\Support\LedgerAccounts;
use App\Support\ReportTable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Laporan arus kas dari jurnal umum.
 *
 * - Metode langsung: setiap jurnal yang menyentuh rekening kas/bank, arus kasnya dibebankan ke akun lawan
 *   (kredit akun lawan = kas masuk). Transfer antar rekening saling meniadakan.
 * - Metode tidak langsung: laba bersih + perubahan saldo akun neraca non-kas (kenaikan aset = kas keluar,
 *   kenaikan kewajiban/ekuitas = kas masuk), dikelompokkan per aktivitas akun.
 *
 * Kedua metode selalu sama dengan perubahan saldo kas karena setiap jurnal seimbang.
 */
class CashFlowReport
{
    /** Keterangan yang lebih mudah dipahami untuk akun lawan yang umum (metode langsung). */
    private const DIRECT_LABELS = [
        'receivable' => 'Penerimaan dari pelanggan',
        'customer_credit' => 'Pengembalian dana retur ke pelanggan',
        'payable' => 'Pembayaran ke supplier',
        'supplier_debit' => 'Pengembalian dana retur dari supplier',
        'wages_payable' => 'Pembayaran upah produksi',
        'employee_advance' => 'Kasbon karyawan (diberikan − dipotong)',
        'wage_allowance' => 'Bonus & tunjangan upah produksi',
        'payroll_other_income' => 'Potongan upah lain',
    ];

    public function __construct(private FinancialReports $reports) {}

    /**
     * @return array{method: string, sections: array<string, array{label: string, items: Collection, total: float}>, opening: float, closing: float, net_change: float, computed_change: float, balanced: bool, accounts: Collection, net_profit: float|null}
     */
    public function build(Carbon $from, Carbon $to, string $method = 'direct'): array
    {
        $accounts = Account::with('parent')->where('is_header', false)->orderBy('code')->get()->keyBy('id');
        $cashIds = $accounts->where('is_cash', true)->keys()->all();

        $before = $this->reports->sums(null, $from->copy()->subDay());
        $movement = $this->reports->sums($from, $to);

        $cashAccounts = $accounts->only($cashIds)->map(function (Account $account) use ($before, $movement) {
            $open = $before->get($account->id, ['debit' => 0, 'credit' => 0]);
            $mov = $movement->get($account->id, ['debit' => 0, 'credit' => 0]);
            $opening = round($open['debit'] - $open['credit'], 2);

            return [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'opening' => $opening,
                'inflow' => $mov['debit'],
                'outflow' => $mov['credit'],
                'closing' => round($opening + $mov['debit'] - $mov['credit'], 2),
            ];
        })->filter(fn ($row) => $row['opening'] || $row['inflow'] || $row['outflow'] || $accounts->get($row['id'])->is_active)->values();

        $opening = round($cashAccounts->sum('opening'), 2);
        $closing = round($cashAccounts->sum('closing'), 2);

        $netProfit = null;
        $items = collect();

        if ($method === 'indirect') {
            $netProfit = $this->reports->profitLoss($from, $to)['net_profit'];
            $items->push(['activity' => CashFlowActivity::Operating, 'code' => '', 'name' => 'Laba (rugi) bersih', 'amount' => $netProfit, 'account_id' => null]);

            foreach ($accounts as $account) {
                if ($account->is_cash || ! $account->type->isBalanceSheet()) {
                    continue;
                }
                $mov = $movement->get($account->id, ['debit' => 0, 'credit' => 0]);
                $amount = round($mov['credit'] - $mov['debit'], 2);

                if (abs($amount) < 0.005) {
                    continue;
                }

                $debitSide = $account->isDebitNormal();
                $verb = ($amount > 0) === $debitSide ? 'Penurunan' : 'Kenaikan';
                $items->push(['activity' => $account->cashFlowActivity(), 'code' => $account->code, 'name' => "{$verb} {$account->name}", 'amount' => $amount, 'account_id' => $account->id]);
            }
        } else {
            $labels = $this->directLabels();
            $flows = empty($cashIds) ? collect() : JournalLine::query()
                ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
                ->whereDate('journal_entries.date', '>=', $from->toDateString())
                ->whereDate('journal_entries.date', '<=', $to->toDateString())
                ->whereIn('journal_lines.journal_entry_id', JournalLine::select('journal_entry_id')->whereIn('account_id', $cashIds))
                ->whereNotIn('journal_lines.account_id', $cashIds)
                ->groupBy('journal_lines.account_id')
                ->selectRaw('journal_lines.account_id, SUM(journal_lines.credit) - SUM(journal_lines.debit) AS flow')
                ->pluck('flow', 'account_id');

            foreach ($flows as $accountId => $flow) {
                $flow = round((float) $flow, 2);
                $account = $accounts->get($accountId);

                if (! $account || abs($flow) < 0.005) {
                    continue;
                }

                $items->push(['activity' => $account->cashFlowActivity(), 'code' => $account->code, 'name' => $labels[$account->id] ?? $account->name, 'amount' => $flow, 'account_id' => $account->id]);
            }

            // Penerimaan di atas, pengeluaran di bawah
            $items = $items->sortBy([fn ($a, $b) => ($b['amount'] > 0) <=> ($a['amount'] > 0), fn ($a, $b) => strcmp($a['code'], $b['code'])])->values();
        }

        $sections = [];
        foreach (CashFlowActivity::cases() as $activity) {
            $rows = $items->where('activity', $activity)->map(fn ($row) => collect($row)->except('activity')->all())->values();
            $sections[$activity->value] = ['label' => $activity->label(), 'items' => $rows, 'total' => round($rows->sum('amount'), 2)];
        }

        $computed = round(array_sum(array_column($sections, 'total')), 2);
        $netChange = round($closing - $opening, 2);

        return [
            'method' => $method,
            'sections' => $sections,
            'opening' => $opening,
            'closing' => $closing,
            'net_change' => $netChange,
            'computed_change' => $computed,
            'balanced' => abs($computed - $netChange) < 0.01,
            'accounts' => $cashAccounts,
            'net_profit' => $netProfit,
        ];
    }

    public function table(array $data, Carbon $from, Carbon $to): ReportTable
    {
        $rows = collect();

        foreach ($data['sections'] as $section) {
            $rows->push(['code' => '', 'name' => strtoupper($section['label']), 'amount' => null]);
            foreach ($section['items'] as $item) {
                $rows->push(['code' => $item['code'], 'name' => '    '.$item['name'], 'amount' => $item['amount']]);
            }
            $rows->push(['code' => '', 'name' => 'Kas bersih dari '.strtolower($section['label']), 'amount' => $section['total']]);
        }

        $rows->push(['code' => '', 'name' => 'KENAIKAN (PENURUNAN) KAS BERSIH', 'amount' => $data['computed_change']]);
        $rows->push(['code' => '', 'name' => 'Saldo kas & bank awal periode', 'amount' => $data['opening']]);
        $rows->push(['code' => '', 'name' => 'SALDO KAS & BANK AKHIR PERIODE', 'amount' => $data['closing']]);

        return new ReportTable(
            'Laporan Arus Kas',
            'Metode '.($data['method'] === 'indirect' ? 'tidak langsung' : 'langsung').' · periode '.tanggal($from).' s.d. '.tanggal($to),
            ['code' => ['Kode', 'text'], 'name' => ['Keterangan', 'text'], 'amount' => ['Jumlah (Rp)', 'money']],
            $rows,
            null,
            [['Saldo kas awal', rupiah($data['opening'])], ['Kenaikan (penurunan) kas', rupiah($data['net_change'])], ['Saldo kas akhir', rupiah($data['closing'])]],
        );
    }

    /**
     * @return array<int, string>
     */
    private function directLabels(): array
    {
        $labels = [];

        foreach (self::DIRECT_LABELS as $role => $label) {
            $account = Account::where('code', LedgerAccounts::code($role))->first();
            if ($account) {
                $labels[$account->id] = $label;
            }
        }

        return $labels;
    }

    /** Akun yang dapat diatur kelompok arus kasnya (akun neraca non-kas). */
    public static function configurable(Account $account): bool
    {
        return ! $account->is_cash && in_array($account->type, [AccountType::Asset, AccountType::Liability, AccountType::Equity], true);
    }
}
