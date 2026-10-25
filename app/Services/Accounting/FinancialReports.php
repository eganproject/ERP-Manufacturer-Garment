<?php

namespace App\Services\Accounting;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\JournalLine;
use App\Support\LedgerAccounts;
use App\Support\ReportTable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Laporan keuangan dari jurnal: buku besar, neraca saldo, laba rugi, dan neraca.
 *
 * Tahun buku = tahun kalender. Akun laba rugi tidak perlu jurnal penutup: saldo laba rugi tahun-tahun
 * sebelumnya otomatis masuk Laba Ditahan, dan laba tahun berjalan dihitung dari 1 Januari.
 */
class FinancialReports
{
    public function __construct(private LedgerAccounts $ledger) {}

    /**
     * Jumlah debit & kredit per akun dalam rentang tanggal (batas null = tak terbatas).
     *
     * @return Collection<int, array{debit: float, credit: float}>
     */
    public function sums(?Carbon $from = null, ?Carbon $to = null): Collection
    {
        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->when($from, fn ($q) => $q->whereDate('journal_entries.date', '>=', $from->toDateString()))
            ->when($to, fn ($q) => $q->whereDate('journal_entries.date', '<=', $to->toDateString()))
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit) AS debit, SUM(journal_lines.credit) AS credit')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->account_id => ['debit' => round((float) $row->debit, 2), 'credit' => round((float) $row->credit, 2)]]);
    }

    /**
     * Saldo awal per akun pada tanggal $from (debit − kredit). Akun laba rugi hanya dihitung sejak awal
     * tahun buku; laba rugi tahun-tahun sebelumnya dipindahkan ke akun Laba Ditahan.
     *
     * @return array<int, float>
     */
    public function openingBalances(Carbon $from, Collection $accounts): array
    {
        $yearStart = $from->copy()->startOfYear();
        $before = $from->copy()->subDay();
        $all = $this->sums(null, $before);
        $ytd = $from->gt($yearStart) ? $this->sums($yearStart, $before) : collect();
        $retainedId = $this->ledger->id('retained_earnings');
        $opening = [];
        $priorProfit = 0.0;

        foreach ($accounts as $account) {
            $sum = $all->get($account->id, ['debit' => 0, 'credit' => 0]);
            $net = $sum['debit'] - $sum['credit'];

            if ($account->type->isBalanceSheet()) {
                $opening[$account->id] = round($net, 2);
            } else {
                $year = $ytd->get($account->id, ['debit' => 0, 'credit' => 0]);
                $opening[$account->id] = round($year['debit'] - $year['credit'], 2);
                $priorProfit += $net - $opening[$account->id];
            }
        }

        $opening[$retainedId] = round(($opening[$retainedId] ?? 0) + $priorProfit, 2);

        return $opening;
    }

    /**
     * Neraca saldo periode.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, float>}
     */
    public function trialBalance(Carbon $from, Carbon $to, bool $includeZero = false): array
    {
        $accounts = Account::where('is_header', false)->orderBy('code')->get();
        $opening = $this->openingBalances($from, $accounts);
        $movement = $this->sums($from, $to);

        $rows = $accounts->map(function (Account $account) use ($opening, $movement) {
            $open = $opening[$account->id] ?? 0;
            $mov = $movement->get($account->id, ['debit' => 0, 'credit' => 0]);
            $close = round($open + $mov['debit'] - $mov['credit'], 2);

            return [
                'id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'type' => $account->type->label(),
                'opening_debit' => $open > 0 ? $open : 0,
                'opening_credit' => $open < 0 ? -$open : 0,
                'debit' => $mov['debit'],
                'credit' => $mov['credit'],
                'closing_debit' => $close > 0 ? $close : 0,
                'closing_credit' => $close < 0 ? -$close : 0,
            ];
        })->filter(fn ($row) => $includeZero || $row['opening_debit'] || $row['opening_credit'] || $row['debit'] || $row['credit'])->values();

        $totals = collect(['opening_debit', 'opening_credit', 'debit', 'credit', 'closing_debit', 'closing_credit'])
            ->mapWithKeys(fn ($key) => [$key => round($rows->sum($key), 2)])->all();

        return compact('rows', 'totals');
    }

    /**
     * Buku besar satu akun: saldo awal, mutasi, dan saldo berjalan (mengikuti saldo normal akun).
     *
     * @return array{opening: float, lines: Collection<int, array<string, mixed>>, debit: float, credit: float, closing: float}
     */
    public function ledger(Account $account, Carbon $from, Carbon $to): array
    {
        $openingNet = $this->openingBalances($from, collect([$account]))[$account->id] ?? 0;
        $sign = $account->isDebitNormal() ? 1 : -1;
        $running = round($openingNet * $sign, 2);

        $lines = JournalLine::query()
            ->with(['entry', 'reference'])
            ->select('journal_lines.*')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $account->id)
            ->whereDate('journal_entries.date', '>=', $from->toDateString())
            ->whereDate('journal_entries.date', '<=', $to->toDateString())
            ->orderBy('journal_entries.date')
            ->orderBy('journal_entries.id')
            ->orderBy('journal_lines.id')
            ->get()
            ->map(function (JournalLine $line) use (&$running, $sign) {
                $running = round($running + ((float) $line->debit - (float) $line->credit) * $sign, 2);

                return [
                    'date' => $line->entry->date,
                    'entry' => $line->entry,
                    'number' => $line->entry->number,
                    'source_number' => $line->entry->source_number,
                    'description' => $line->description ?: $line->entry->description,
                    'reference' => $line->referenceLabel(),
                    'debit' => (float) $line->debit,
                    'credit' => (float) $line->credit,
                    'balance' => $running,
                ];
            });

        return [
            'opening' => round($openingNet * $sign, 2),
            'lines' => $lines,
            'debit' => round($lines->sum('debit'), 2),
            'credit' => round($lines->sum('credit'), 2),
            'closing' => $running,
        ];
    }

    /**
     * Laba rugi periode, bertingkat: pendapatan bersih → laba kotor → laba usaha → laba bersih.
     *
     * @return array{sections: array<string, array{label: string, accounts: Collection, total: float}>, net_revenue: float, gross_profit: float, operating_profit: float, net_profit: float}
     */
    public function profitLoss(Carbon $from, Carbon $to): array
    {
        $sums = $this->sums($from, $to);
        $accounts = Account::where('is_header', false)->whereIn('type', array_map(fn ($t) => $t->value, AccountType::profitLoss()))->orderBy('code')->get();
        $sections = [];

        foreach (AccountType::profitLoss() as $type) {
            $credit = in_array($type, [AccountType::Revenue, AccountType::OtherIncome], true);
            $rows = $accounts->where('type', $type)->map(function (Account $account) use ($sums, $credit) {
                $sum = $sums->get($account->id, ['debit' => 0, 'credit' => 0]);

                return ['id' => $account->id, 'code' => $account->code, 'name' => $account->name, 'amount' => round($credit ? $sum['credit'] - $sum['debit'] : $sum['debit'] - $sum['credit'], 2)];
            })->filter(fn ($row) => abs($row['amount']) >= 0.005)->values();

            $sections[$type->value] = ['label' => $type->label(), 'accounts' => $rows, 'total' => round($rows->sum('amount'), 2)];
        }

        $netRevenue = $sections[AccountType::Revenue->value]['total'];
        $gross = round($netRevenue - $sections[AccountType::CostOfSales->value]['total'], 2);
        $operating = round($gross - $sections[AccountType::Expense->value]['total'], 2);
        $net = round($operating + $sections[AccountType::OtherIncome->value]['total'] - $sections[AccountType::OtherExpense->value]['total'], 2);

        return [
            'sections' => $sections,
            'net_revenue' => $netRevenue,
            'gross_profit' => $gross,
            'operating_profit' => $operating,
            'net_profit' => $net,
        ];
    }

    /**
     * Neraca per tanggal. Ekuitas ditambah laba ditahan (laba rugi tahun lalu) dan laba tahun berjalan.
     *
     * @return array{groups: array<string, array{label: string, groups: Collection, total: float}>, current_profit: float, retained_profit: float, balanced: bool, difference: float}
     */
    public function balanceSheet(Carbon $asOf): array
    {
        $accounts = Account::with('parent')->where('is_header', false)->orderBy('code')->get();
        $all = $this->sums(null, $asOf);
        $yearStart = $asOf->copy()->startOfYear();
        $currentProfit = $this->profitLoss($yearStart, $asOf)['net_profit'];

        $totalProfit = 0.0;
        foreach ($accounts->filter(fn ($a) => ! $a->type->isBalanceSheet()) as $account) {
            $sum = $all->get($account->id, ['debit' => 0, 'credit' => 0]);
            $totalProfit += $sum['credit'] - $sum['debit'];
        }
        $retainedProfit = round($totalProfit - $currentProfit, 2);

        $result = [];
        foreach ([AccountType::Asset, AccountType::Liability, AccountType::Equity] as $type) {
            $debitSide = $type === AccountType::Asset;
            $rows = $accounts->where('type', $type)->map(function (Account $account) use ($all, $debitSide) {
                $sum = $all->get($account->id, ['debit' => 0, 'credit' => 0]);

                return [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'group' => $account->parent && $account->parent->parent_id ? $account->parent->name : null,
                    'amount' => round($debitSide ? $sum['debit'] - $sum['credit'] : $sum['credit'] - $sum['debit'], 2),
                ];
            });

            if ($type === AccountType::Equity) {
                $retainedId = $this->ledger->id('retained_earnings');
                $rows = $rows->map(fn ($row) => $row['id'] === $retainedId ? ['amount' => round($row['amount'] + $retainedProfit, 2)] + $row : $row);
                $rows->push(['id' => null, 'code' => '', 'name' => 'Laba (Rugi) Tahun Berjalan', 'group' => null, 'amount' => $currentProfit]);
            }

            $rows = $rows->filter(fn ($row) => abs($row['amount']) >= 0.005)->values();

            $result[$type->value] = [
                'label' => $type->label(),
                'groups' => $rows->groupBy(fn ($row) => $row['group'] ?? ''),
                'total' => round($rows->sum('amount'), 2),
            ];
        }

        $difference = round($result['asset']['total'] - $result['liability']['total'] - $result['equity']['total'], 2);

        return [
            'groups' => $result,
            'current_profit' => $currentProfit,
            'retained_profit' => $retainedProfit,
            'balanced' => abs($difference) < 0.01,
            'difference' => $difference,
        ];
    }

    // ---- Tabel ekspor -------------------------------------------------------

    public function trialBalanceTable(array $data, Carbon $from, Carbon $to): ReportTable
    {
        return new ReportTable(
            'Neraca Saldo',
            'Periode '.tanggal($from).' s.d. '.tanggal($to),
            [
                'code' => ['Kode', 'text'],
                'name' => ['Akun', 'text'],
                'opening_debit' => ['Saldo Awal Debit', 'money'],
                'opening_credit' => ['Saldo Awal Kredit', 'money'],
                'debit' => ['Mutasi Debit', 'money'],
                'credit' => ['Mutasi Kredit', 'money'],
                'closing_debit' => ['Saldo Akhir Debit', 'money'],
                'closing_credit' => ['Saldo Akhir Kredit', 'money'],
            ],
            $data['rows'],
            $data['totals'],
            orientation: 'landscape',
        );
    }

    public function ledgerTable(Account $account, array $data, Carbon $from, Carbon $to): ReportTable
    {
        $rows = collect([['date' => null, 'number' => '', 'source_number' => '', 'description' => 'Saldo awal', 'reference' => '', 'debit' => null, 'credit' => null, 'balance' => $data['opening']]])
            ->concat($data['lines']->map(fn ($l) => collect($l)->except('entry')->all()));

        return new ReportTable(
            'Buku Besar '.$account->code.' '.$account->name,
            'Periode '.tanggal($from).' s.d. '.tanggal($to).' · saldo normal '.$account->normal_balance,
            [
                'date' => ['Tanggal', 'date'],
                'number' => ['No. Jurnal', 'text'],
                'source_number' => ['Dokumen', 'text'],
                'description' => ['Keterangan', 'text'],
                'reference' => ['Rincian', 'text'],
                'debit' => ['Debit', 'money'],
                'credit' => ['Kredit', 'money'],
                'balance' => ['Saldo', 'money'],
            ],
            $rows,
            ['debit' => $data['debit'], 'credit' => $data['credit'], 'balance' => $data['closing']],
            orientation: 'landscape',
        );
    }

    public function profitLossTable(array $data, Carbon $from, Carbon $to): ReportTable
    {
        $rows = collect();
        $section = function (string $type) use ($data, $rows) {
            $s = $data['sections'][$type];
            $rows->push(['code' => '', 'name' => strtoupper($s['label']), 'amount' => null]);
            foreach ($s['accounts'] as $a) {
                $rows->push(['code' => $a['code'], 'name' => '    '.$a['name'], 'amount' => $a['amount']]);
            }
            $rows->push(['code' => '', 'name' => 'Total '.$s['label'], 'amount' => $s['total']]);
        };
        $subtotal = fn (string $label, float $amount) => $rows->push(['code' => '', 'name' => $label, 'amount' => $amount]);

        $section(AccountType::Revenue->value);
        $section(AccountType::CostOfSales->value);
        $subtotal('LABA KOTOR', $data['gross_profit']);
        $section(AccountType::Expense->value);
        $subtotal('LABA USAHA', $data['operating_profit']);
        $section(AccountType::OtherIncome->value);
        $section(AccountType::OtherExpense->value);
        $subtotal('LABA (RUGI) BERSIH', $data['net_profit']);

        return new ReportTable(
            'Laporan Laba Rugi',
            'Periode '.tanggal($from).' s.d. '.tanggal($to),
            ['code' => ['Kode', 'text'], 'name' => ['Keterangan', 'text'], 'amount' => ['Jumlah (Rp)', 'money']],
            $rows,
            null,
            [['Laba (rugi) bersih', rupiah($data['net_profit'])]],
        );
    }

    public function balanceSheetTable(array $data, Carbon $asOf): ReportTable
    {
        $rows = collect();

        foreach ($data['groups'] as $type => $section) {
            $rows->push(['code' => '', 'name' => strtoupper($section['label']), 'amount' => null]);
            foreach ($section['groups'] as $group => $items) {
                if ($group !== '') {
                    $rows->push(['code' => '', 'name' => '  '.$group, 'amount' => null]);
                }
                foreach ($items as $item) {
                    $rows->push(['code' => $item['code'], 'name' => '    '.$item['name'], 'amount' => $item['amount']]);
                }
            }
            $rows->push(['code' => '', 'name' => 'TOTAL '.strtoupper($section['label']), 'amount' => $section['total']]);
        }

        $liabEquity = $data['groups']['liability']['total'] + $data['groups']['equity']['total'];
        $rows->push(['code' => '', 'name' => 'TOTAL KEWAJIBAN + EKUITAS', 'amount' => $liabEquity]);

        return new ReportTable(
            'Neraca',
            'Per '.tanggal($asOf),
            ['code' => ['Kode', 'text'], 'name' => ['Keterangan', 'text'], 'amount' => ['Jumlah (Rp)', 'money']],
            $rows,
            null,
            [['Total aset', rupiah($data['groups']['asset']['total'])], ['Total kewajiban + ekuitas', rupiah($liabEquity)]],
        );
    }
}
