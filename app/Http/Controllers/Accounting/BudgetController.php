<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\Budget;
use App\Services\Accounting\BudgetReport;
use App\Support\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Anggaran tahunan per akun per bulan dan laporan anggaran vs realisasi.
 */
class BudgetController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:budget.view', only: ['index', 'show']),
            new Middleware('can:budget.manage', only: ['create', 'store', 'edit', 'update', 'destroy']),
        ];
    }

    public function index(Request $request)
    {
        $budgets = Budget::with(['lines.account', 'creator'])
            ->when($request->integer('year'), fn ($q, $year) => $q->where('year', $year))
            ->orderByDesc('year')->orderBy('name')
            ->get();

        $summaries = $budgets->mapWithKeys(function (Budget $budget) {
            $byType = fn (AccountType $type) => round($budget->lines->filter(fn ($l) => $l->account->type === $type)
                ->sum(fn ($l) => ($l->account->normal_balance === $type->normalBalance() ? 1 : -1) * (float) $l->amount), 2);

            $revenue = $byType(AccountType::Revenue);
            $costs = $byType(AccountType::CostOfSales) + $byType(AccountType::Expense) + $byType(AccountType::OtherExpense) - $byType(AccountType::OtherIncome);

            return [$budget->id => ['revenue' => $revenue, 'costs' => round($costs, 2), 'profit' => round($revenue - $costs, 2), 'accounts' => $budget->lines->pluck('account_id')->unique()->count()]];
        });

        return view('accounting.budgets.index', compact('budgets', 'summaries'));
    }

    public function create(Request $request, BudgetReport $report)
    {
        $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'copy' => ['nullable', 'integer', 'exists:budgets,id'],
            'actual_year' => ['nullable', 'integer', 'between:2000,2100'],
            'growth' => ['nullable', 'numeric', 'between:-100,1000'],
        ]);
        $year = $request->integer('year') ?: today()->year;
        $budget = new Budget(['year' => $year, 'name' => 'Anggaran '.$year]);
        $rows = [];

        if ($request->integer('copy')) {
            // Salin anggaran lain (mis. revisi atau tahun berikutnya)
            $source = Budget::with('lines')->find($request->integer('copy'));
            $budget->description = 'Disalin dari '.$source->name;
            $rows = $this->rowsFromMatrix($source->matrix(), (float) $request->input('growth', 0));
        } elseif ($request->integer('actual_year')) {
            // Isi dari realisasi laba rugi tahun tertentu ± pertumbuhan
            $actualYear = $request->integer('actual_year');
            $plIds = Account::whereIn('type', array_map(fn ($t) => $t->value, AccountType::profitLoss()))->pluck('id')->flip();
            $matrix = array_intersect_key($report->monthlyActuals($actualYear), $plIds->all());
            $budget->description = "Berdasarkan realisasi {$actualYear}".($request->filled('growth') ? ' '.($request->growth >= 0 ? '+' : '').$request->growth.'%' : '');
            $rows = $this->rowsFromMatrix($matrix, (float) $request->input('growth', 0));
        }

        return view('accounting.budgets.form', $this->formData($budget, $rows));
    }

    public function store(Request $request)
    {
        [$data, $matrix] = $this->validated($request);

        $budget = DB::transaction(function () use ($data, $matrix) {
            $budget = Budget::create($data + ['created_by' => auth()->id()]);
            $this->saveLines($budget, $matrix);
            ActivityLog::record('created', "Menyusun anggaran {$budget->name} ({$budget->year})", $budget);

            return $budget;
        });

        return redirect()->route('budgets.show', $budget)->with('success', "Anggaran {$budget->name} tersimpan.");
    }

    public function show(Request $request, Budget $budget, BudgetReport $report)
    {
        $request->validate([
            'from_month' => ['nullable', 'integer', 'between:1,12'],
            'to_month' => ['nullable', 'integer', 'between:1,12', 'gte:from_month'],
            'export' => ['nullable', Rule::in(['xlsx', 'pdf'])],
        ]);

        // Default: awal tahun s.d. bulan berjalan (tahun berjalan) atau setahun penuh
        $defaultTo = $budget->year == today()->year ? today()->month : 12;
        $fromMonth = $request->integer('from_month') ?: 1;
        $toMonth = $request->integer('to_month') ?: max($fromMonth, $defaultTo);
        $data = $report->compare($budget, $fromMonth, $toMonth);

        if ($response = ReportExport::respond($report->table($budget, $data), $request->query('export'))) {
            return $response;
        }

        $budget->load(['creator', 'activities.user']);

        return view('accounting.budgets.show', $data + compact('budget', 'fromMonth', 'toMonth'));
    }

    public function edit(Budget $budget)
    {
        $budget->load('lines');

        return view('accounting.budgets.form', $this->formData($budget, $this->rowsFromMatrix($budget->matrix())));
    }

    public function update(Request $request, Budget $budget)
    {
        [$data, $matrix] = $this->validated($request, $budget);

        DB::transaction(function () use ($budget, $data, $matrix) {
            $budget->update($data);
            $budget->lines()->delete();
            $this->saveLines($budget, $matrix);
            ActivityLog::record('updated', 'Mengubah anggaran', $budget);
        });

        return redirect()->route('budgets.show', $budget)->with('success', "Anggaran {$budget->name} diperbarui.");
    }

    public function destroy(Budget $budget)
    {
        $budget->delete();

        return redirect()->route('budgets.index')->with('success', "Anggaran {$budget->name} dihapus.");
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, array<int, float>>}
     */
    private function validated(Request $request, ?Budget $budget = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('budgets')->where('year', $request->integer('year'))->ignore($budget?->id)],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array'],
            'lines.*.account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'lines.*.months' => ['nullable', 'array'],
            'lines.*.months.*' => ['nullable', 'numeric'],
        ], ['name.unique' => 'Nama anggaran sudah dipakai untuk tahun ini.'], ['name' => 'nama anggaran', 'year' => 'tahun']);

        $accounts = Account::whereIn('id', array_filter(array_column($data['lines'], 'account_id')))->get()->keyBy('id');
        $matrix = [];

        foreach (array_values($data['lines']) as $i => $line) {
            $months = array_map(fn ($v) => round((float) $v, 2), array_slice(array_pad(array_values($line['months'] ?? []), 12, 0), 0, 12));

            if (empty($line['account_id'])) {
                if (array_sum(array_map('abs', $months)) > 0) {
                    throw ValidationException::withMessages(["lines.{$i}.account_id" => 'Baris '.($i + 1).': pilih akun.']);
                }

                continue;
            }

            $account = $accounts[$line['account_id']];

            if ($account->is_header) {
                throw ValidationException::withMessages(["lines.{$i}.account_id" => 'Baris '.($i + 1).": {$account->display_name} adalah akun induk."]);
            }
            if (isset($matrix[$account->id])) {
                throw ValidationException::withMessages(["lines.{$i}.account_id" => "Akun {$account->display_name} muncul lebih dari sekali."]);
            }

            $matrix[$account->id] = array_combine(range(1, 12), $months);
        }

        if (empty($matrix)) {
            throw ValidationException::withMessages(['lines' => 'Isi minimal satu akun anggaran.']);
        }

        return [['name' => $data['name'], 'year' => $data['year'], 'description' => $data['description'] ?? null], $matrix];
    }

    private function saveLines(Budget $budget, array $matrix): void
    {
        $rows = [];

        foreach ($matrix as $accountId => $months) {
            foreach ($months as $month => $amount) {
                if (abs($amount) >= 0.005) {
                    $rows[] = ['account_id' => $accountId, 'month' => $month, 'amount' => $amount];
                }
            }
        }

        // Akun tanpa nilai tetap disimpan (bulan 1 = 0) agar muncul di laporan
        foreach (array_keys($matrix) as $accountId) {
            if (! collect($rows)->contains('account_id', $accountId)) {
                $rows[] = ['account_id' => $accountId, 'month' => 1, 'amount' => 0];
            }
        }

        $budget->lines()->createMany($rows);
    }

    /**
     * @return list<array{account_id: int, months: list<float>}>
     */
    private function rowsFromMatrix(array $matrix, float $growth = 0): array
    {
        $factor = 1 + $growth / 100;
        $codes = Account::whereIn('id', array_keys($matrix))->pluck('code', 'id');

        return collect($matrix)
            ->sortBy(fn ($months, $id) => $codes[$id] ?? '')
            ->map(fn ($months, $id) => ['account_id' => (int) $id, 'months' => array_values(array_map(fn ($v) => round($v * $factor), array_replace(array_fill(1, 12, 0.0), $months)))])
            ->values()
            ->all();
    }

    private function formData(Budget $budget, array $rows): array
    {
        $types = AccountType::profitLoss();

        return [
            'budget' => $budget,
            'rows' => $rows,
            // Akun laba rugi dulu, lalu akun neraca (mis. anggaran pembelian aset tetap)
            'accounts' => Account::postable()->orderByRaw('CASE WHEN type IN ('.implode(',', array_fill(0, count($types), '?')).') THEN 0 ELSE 1 END', array_map(fn ($t) => $t->value, $types))
                ->orderBy('code')->get()
                ->mapWithKeys(fn ($a) => [$a->id => $a->display_name.' · '.$a->type->label()]),
            'budgets' => Budget::where('id', '!=', $budget->id ?? 0)->orderByDesc('year')->get(),
        ];
    }
}
