<?php

namespace App\Services\Accounting;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Budget;
use App\Support\ReportTable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Anggaran vs realisasi. Nilai anggaran & realisasi positif menurut saldo normal akun (pendapatan = kredit,
 * beban = debit). Akun kontra (retur/potongan penjualan) mengurangi total golongannya, sama seperti laba rugi.
 */
class BudgetReport
{
    public function __construct(private FinancialReports $reports) {}

    /** Realisasi akun pada rentang tanggal, bertanda saldo normal akun. */
    public function actuals(Carbon $from, Carbon $to): Collection
    {
        $accounts = Account::where('is_header', false)->get()->keyBy('id');

        return $this->reports->sums($from, $to)
            ->map(fn ($sum, $id) => $accounts->has($id) ? $accounts[$id]->signedBalance($sum['debit'], $sum['credit']) : 0.0);
    }

    /**
     * Realisasi per akun per bulan untuk satu tahun (untuk mengisi anggaran dari data tahun lalu).
     *
     * @return array<int, array<int, float>> account_id => [1..12 => jumlah]
     */
    public function monthlyActuals(int $year): array
    {
        $matrix = [];

        for ($month = 1; $month <= 12; $month++) {
            $start = Carbon::create($year, $month, 1);

            foreach ($this->actuals($start, $start->copy()->endOfMonth()) as $accountId => $amount) {
                if (abs($amount) >= 0.005) {
                    $matrix[$accountId] ??= array_fill(1, 12, 0.0);
                    $matrix[$accountId][$month] = $amount;
                }
            }
        }

        return $matrix;
    }

    /**
     * @return array{groups: array<string, array{label: string, rows: Collection, budget: float, actual: float, variance: float}>, profit: array{budget: float, actual: float, variance: float}, months: array<int, array<string, float>>, from: Carbon, to: Carbon}
     */
    public function compare(Budget $budget, int $fromMonth = 1, int $toMonth = 12): array
    {
        $budget->loadMissing('lines');
        $from = Carbon::create($budget->year, $fromMonth, 1);
        $to = Carbon::create($budget->year, $toMonth, 1)->endOfMonth();
        $matrix = $budget->matrix();
        $actuals = $this->actuals($from, $to);

        // Akun beranggaran + akun laba rugi yang ada realisasinya (pengeluaran di luar anggaran tetap terlihat)
        $profitLossTypes = array_map(fn ($t) => $t->value, AccountType::profitLoss());
        $ids = collect(array_keys($matrix))
            ->merge($actuals->filter(fn ($v) => abs($v) >= 0.005)->keys())
            ->unique();
        $accounts = Account::whereIn('id', $ids)->orderBy('code')->get()
            ->filter(fn (Account $a) => isset($matrix[$a->id]) || in_array($a->type->value, $profitLossTypes, true));

        $groups = [];
        foreach (AccountType::cases() as $type) {
            $rows = $accounts->where('type', $type)->map(function (Account $account) use ($matrix, $actuals, $fromMonth, $toMonth, $type) {
                $months = $matrix[$account->id] ?? [];
                $planned = round(array_sum(array_filter($months, fn ($m) => $m >= $fromMonth && $m <= $toMonth, ARRAY_FILTER_USE_KEY)), 2);
                $actual = round((float) $actuals->get($account->id, 0), 2);
                $contra = $account->normal_balance !== $type->normalBalance();
                $creditType = $type->normalBalance() === 'credit';
                // Pendapatan: realisasi di atas anggaran = baik; beban: di bawah anggaran = baik (kebalikan untuk akun kontra)
                $higherIsGood = $creditType !== $contra;

                return [
                    'id' => $account->id,
                    'code' => $account->code,
                    'name' => $account->name,
                    'contra' => $contra,
                    'budget' => $planned,
                    'actual' => $actual,
                    'variance' => round($actual - $planned, 2),
                    'percent' => $planned != 0 ? round($actual / $planned * 100, 1) : null,
                    'favorable' => abs($actual - $planned) < 0.005 ? null : (($actual > $planned) === $higherIsGood),
                ];
            })->values();

            if ($rows->isEmpty()) {
                continue;
            }

            $sum = fn (string $key) => round($rows->sum(fn ($r) => $r['contra'] ? -$r[$key] : $r[$key]), 2);
            $groups[$type->value] = [
                'label' => $type->label(),
                'rows' => $rows,
                'budget' => $sum('budget'),
                'actual' => $sum('actual'),
                'variance' => $sum('variance'),
            ];
        }

        $profit = fn (string $key) => round(
            ($groups['revenue'][$key] ?? 0) - ($groups['cost_of_sales'][$key] ?? 0) - ($groups['expense'][$key] ?? 0)
            + ($groups['other_income'][$key] ?? 0) - ($groups['other_expense'][$key] ?? 0), 2);

        return [
            'groups' => $groups,
            'profit' => ['budget' => $profit('budget'), 'actual' => $profit('actual'), 'variance' => $profit('variance')],
            'months' => $this->monthlySummary($budget, $matrix),
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * Ringkasan per bulan: pendapatan bersih, beban (HPP + usaha + lain-lain bersih) dan laba — anggaran vs realisasi.
     *
     * @return array<int, array<string, float|null>>
     */
    private function monthlySummary(Budget $budget, array $matrix): array
    {
        $accounts = Account::where('is_header', false)->whereIn('type', array_map(fn ($t) => $t->value, AccountType::profitLoss()))->get()->keyBy('id');
        // Kontribusi ke laba: + untuk pendapatan (kredit), − untuk beban; dihitung dari debit-kredit agar kontra ikut benar
        $profitSign = fn (Account $a) => $a->isDebitNormal() ? -1 : 1;
        $isRevenue = fn (Account $a) => $a->type === AccountType::Revenue;
        $today = today();
        $result = [];

        for ($month = 1; $month <= 12; $month++) {
            $start = Carbon::create($budget->year, $month, 1);
            $row = ['budget_revenue' => 0.0, 'budget_profit' => 0.0, 'actual_revenue' => null, 'actual_profit' => null];

            foreach ($matrix as $accountId => $months) {
                $account = $accounts->get($accountId);
                if (! $account) {
                    continue;
                }
                $value = $profitSign($account) * (float) ($months[$month] ?? 0);
                $row['budget_profit'] += $value;
                if ($isRevenue($account)) {
                    $row['budget_revenue'] += $value;
                }
            }

            if ($start->lte($today)) {
                $row['actual_revenue'] = 0.0;
                $row['actual_profit'] = 0.0;
                foreach ($this->actuals($start, $start->copy()->endOfMonth()) as $accountId => $amount) {
                    $account = $accounts->get($accountId);
                    if (! $account) {
                        continue;
                    }
                    $value = $profitSign($account) * $amount;
                    $row['actual_profit'] += $value;
                    if ($isRevenue($account)) {
                        $row['actual_revenue'] += $value;
                    }
                }
            }

            $result[$month] = array_map(fn ($v) => $v === null ? null : round($v, 2), $row);
        }

        return $result;
    }

    public function table(Budget $budget, array $data): ReportTable
    {
        $rows = collect();

        foreach ($data['groups'] as $group) {
            $rows->push(['code' => '', 'name' => strtoupper($group['label']), 'budget' => null, 'actual' => null, 'variance' => null, 'percent' => null]);
            foreach ($group['rows'] as $row) {
                $rows->push(['code' => $row['code'], 'name' => '    '.$row['name'], 'budget' => $row['budget'], 'actual' => $row['actual'], 'variance' => $row['variance'], 'percent' => $row['percent']]);
            }
            $rows->push(['code' => '', 'name' => 'Total '.$group['label'], 'budget' => $group['budget'], 'actual' => $group['actual'], 'variance' => $group['variance'], 'percent' => $group['budget'] != 0 ? round($group['actual'] / $group['budget'] * 100, 1) : null]);
        }

        $p = $data['profit'];
        $rows->push(['code' => '', 'name' => 'LABA (RUGI) BERSIH', 'budget' => $p['budget'], 'actual' => $p['actual'], 'variance' => $p['variance'], 'percent' => $p['budget'] != 0 ? round($p['actual'] / $p['budget'] * 100, 1) : null]);

        return new ReportTable(
            'Anggaran vs Realisasi '.$budget->name,
            'Periode '.tanggal($data['from']).' s.d. '.tanggal($data['to']),
            [
                'code' => ['Kode', 'text'],
                'name' => ['Akun', 'text'],
                'budget' => ['Anggaran', 'money'],
                'actual' => ['Realisasi', 'money'],
                'variance' => ['Selisih', 'money'],
                'percent' => ['Realisasi %', 'percent'],
            ],
            $rows,
            null,
            [['Laba bersih dianggarkan', rupiah($p['budget'])], ['Laba bersih realisasi', rupiah($p['actual'])]],
            orientation: 'landscape',
        );
    }
}
