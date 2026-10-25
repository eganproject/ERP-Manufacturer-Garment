<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Setting;
use App\Services\Accounting\FinancialReports;
use App\Support\LedgerAccounts;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Bagan akun (COA) dan pemetaan akun jurnal otomatis.
 */
class AccountController extends Controller
{
    public function index(Request $request, FinancialReports $reports)
    {
        $accounts = Account::with('parent.parent')
            ->when($request->type, fn ($q, $type) => $q->where('type', $type))
            ->when($request->q, fn ($q, $search) => $q->where(fn ($q) => $q->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('code')
            ->get();

        $sums = $reports->sums(null, today());
        $balances = $accounts->mapWithKeys(function (Account $account) use ($sums) {
            $sum = $sums->get($account->id, ['debit' => 0, 'credit' => 0]);

            return [$account->id => $account->signedBalance($sum['debit'], $sum['credit'])];
        });

        // Saldo akun induk = jumlah akun di bawahnya (saldo normal yang sama dijumlahkan, kontra dikurangkan)
        $all = Account::get(['id', 'parent_id', 'normal_balance', 'is_header']);
        $headerTotals = [];
        foreach ($all->where('is_header', false) as $account) {
            $sum = $sums->get($account->id, ['debit' => 0, 'credit' => 0]);
            $net = $sum['debit'] - $sum['credit'];
            $parentId = $account->parent_id;
            while ($parentId) {
                $headerTotals[$parentId] = ($headerTotals[$parentId] ?? 0) + $net;
                $parentId = $all->firstWhere('id', $parentId)?->parent_id;
            }
        }
        foreach ($accounts->where('is_header', true) as $header) {
            $net = $headerTotals[$header->id] ?? 0;
            $balances[$header->id] = round($header->isDebitNormal() ? $net : -$net, 2);
        }

        return view('accounting.accounts.index', compact('accounts', 'balances'));
    }

    public function create(Request $request)
    {
        $account = new Account([
            'type' => AccountType::tryFrom((string) $request->type) ?? AccountType::Expense,
            'parent_id' => $request->integer('parent_id') ?: null,
            'is_active' => true,
        ]);

        return view('accounting.accounts.form', $this->formData($account));
    }

    public function store(Request $request)
    {
        $account = Account::create($this->validated($request));

        return redirect()->route('accounts.index')->with('success', "Akun {$account->display_name} ditambahkan.");
    }

    public function edit(Account $account)
    {
        return view('accounting.accounts.form', $this->formData($account));
    }

    public function update(Request $request, Account $account)
    {
        $data = $this->validated($request, $account);

        // Akun sistem / yang sudah dipakai jurnal: kode, tipe & jenis akun dikunci
        if ($account->is_system || $account->lines()->exists()) {
            $data = array_intersect_key($data, array_flip(['name', 'description', 'is_active', 'parent_id']));
            $data['is_active'] = $account->is_system ? true : $data['is_active'];
        }

        $account->update($data);

        return redirect()->route('accounts.index')->with('success', "Akun {$account->display_name} diperbarui.");
    }

    public function destroy(Account $account)
    {
        if (! $account->canDelete()) {
            return back()->with('error', 'Akun sistem, akun induk yang punya sub-akun, atau akun yang sudah dipakai jurnal tidak dapat dihapus. Nonaktifkan saja.');
        }

        $account->delete();

        return redirect()->route('accounts.index')->with('success', 'Akun dihapus.');
    }

    public function mapping()
    {
        return view('accounting.accounts.mapping', [
            'roles' => collect(LedgerAccounts::ROLES)->groupBy(fn ($r) => $r[2], true),
            'current' => collect(LedgerAccounts::ROLES)->mapWithKeys(fn ($r, $role) => [$role => LedgerAccounts::code($role)]),
            'options' => Account::postable()->orderBy('code')->get()->mapWithKeys(fn ($a) => [$a->code => $a->display_name]),
        ]);
    }

    public function updateMapping(Request $request, LedgerAccounts $ledger)
    {
        $codes = Account::postable()->pluck('code')->all();
        $data = $request->validate([
            'roles' => ['required', 'array'],
            'roles.*' => ['required', Rule::in($codes)],
        ], ['roles.*.in' => 'Pilih akun detail yang aktif.']);

        $values = [];
        foreach (array_keys(LedgerAccounts::ROLES) as $role) {
            if (isset($data['roles'][$role])) {
                $values['gl_'.$role] = $data['roles'][$role];
            }
        }
        Setting::put($values);
        $ledger->forget();

        return back()->with('success', 'Pemetaan akun jurnal otomatis disimpan. Berlaku untuk transaksi berikutnya.');
    }

    private function validated(Request $request, ?Account $account = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'regex:/^[0-9A-Za-z.\-]+$/', Rule::unique('accounts', 'code')->ignore($account?->id)],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'parent_id' => ['nullable', Rule::exists('accounts', 'id')->where('is_header', true)],
            'normal_balance' => ['nullable', Rule::in(['debit', 'credit'])],
            'is_header' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
            'description' => ['nullable', 'string', 'max:255'],
        ], [
            'code.regex' => 'Kode akun hanya boleh berisi angka, huruf, titik, atau tanda minus.',
            'parent_id.exists' => 'Akun induk harus berupa akun header.',
        ]);

        $type = AccountType::from($data['type']);

        if (! empty($data['parent_id'])) {
            $parent = Account::find($data['parent_id']);

            if ($parent->type !== $type) {
                throw ValidationException::withMessages(['parent_id' => "Akun induk {$parent->display_name} bertipe {$parent->type->label()}, harus sama dengan tipe akun."]);
            }

            if ($account && $parent->id === $account->id) {
                throw ValidationException::withMessages(['parent_id' => 'Akun tidak boleh menjadi induk dirinya sendiri.']);
            }
        }

        return [
            'code' => $data['code'],
            'name' => $data['name'],
            'type' => $type,
            'parent_id' => $data['parent_id'] ?? null,
            'normal_balance' => $data['normal_balance'] ?? $type->normalBalance(),
            'is_header' => $request->boolean('is_header'),
            'is_active' => $request->boolean('is_active'),
            'description' => $data['description'] ?? null,
        ];
    }

    private function formData(Account $account): array
    {
        return [
            'account' => $account,
            'parents' => Account::where('is_header', true)->orderBy('code')->get()->mapWithKeys(fn ($a) => [$a->id => $a->display_name.' ('.$a->type->label().')']),
            'locked' => $account->exists && ($account->is_system || $account->lines()->exists()),
        ];
    }
}
