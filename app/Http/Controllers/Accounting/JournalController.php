<?php

namespace App\Http\Controllers\Accounting;

use App\Enums\JournalType;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\ActivityLog;
use App\Models\JournalEntry;
use App\Services\Accounting\JournalService;
use App\Services\Accounting\LedgerRebuilder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Daftar jurnal umum (otomatis & manual) dan input jurnal manual (penyesuaian, saldo awal, biaya, dsb).
 */
class JournalController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:accounting.view', only: ['index', 'show']),
            new Middleware('can:accounting.manage', only: ['create', 'store', 'reverse']),
            new Middleware('can:accounting.close', only: ['rebuild']),
        ];
    }

    public function index(Request $request)
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'type' => ['nullable', Rule::enum(JournalType::class)],
            'account_id' => ['nullable', 'integer'],
        ]);

        $from = $request->from ? Carbon::parse($request->from) : today()->startOfMonth();
        $to = $request->to ? Carbon::parse($request->to) : today();

        $entries = JournalEntry::query()
            ->with(['lines.account', 'creator'])
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->when($request->type, fn ($q, $type) => $q->where('type', $type))
            ->when($request->account_id, fn ($q, $id) => $q->whereHas('lines', fn ($q) => $q->where('account_id', $id)))
            ->when($request->q, fn ($q, $search) => $q->where(fn ($q) => $q
                ->where('number', 'like', "%{$search}%")
                ->orWhere('source_number', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('accounting.journals.index', [
            'entries' => $entries,
            'from' => $from,
            'to' => $to,
            'accounts' => Account::options(),
            'hasAuto' => JournalEntry::where('type', JournalType::Auto)->exists(),
            'lockedUntil' => AccountingPeriod::lockedUntil(),
        ]);
    }

    public function show(JournalEntry $journal)
    {
        $journal->load(['lines.account', 'lines.reference', 'creator', 'reversal', 'reversalOf', 'source']);

        return view('accounting.journals.show', ['entry' => $journal]);
    }

    public function create(Request $request)
    {
        $copy = $request->integer('copy') ? JournalEntry::with('lines')->find($request->integer('copy')) : null;

        return view('accounting.journals.create', [
            'accounts' => Account::options(),
            'lines' => $copy?->lines->map(fn ($l) => ['account_id' => $l->account_id, 'description' => $l->description, 'debit' => (float) $l->debit ?: '', 'credit' => (float) $l->credit ?: ''])->all() ?? [],
            'description' => $copy?->description,
            'lockedUntil' => AccountingPeriod::lockedUntil(),
        ]);
    }

    public function store(Request $request, JournalService $journals)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:50'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer', 'exists:accounts,id'],
            'lines.*.description' => ['nullable', 'string', 'max:255'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
        ], [
            'lines.min' => 'Jurnal minimal terdiri dari 2 baris.',
            'lines.*.account_id.required' => 'Pilih akun pada setiap baris.',
        ]);

        $accounts = Account::whereIn('id', array_column($data['lines'], 'account_id'))->get()->keyBy('id');
        $lines = [];

        foreach (array_values($data['lines']) as $i => $line) {
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);

            if (($debit > 0) === ($credit > 0)) {
                throw ValidationException::withMessages(["lines.{$i}.debit" => 'Baris '.($i + 1).': isi debit atau kredit (salah satu).']);
            }

            JournalService::assertPostable($accounts[$line['account_id']]);

            $lines[] = ['account' => (int) $line['account_id'], 'debit' => $debit, 'credit' => $credit, 'description' => $line['description'] ?? null];
        }

        $entry = DB::transaction(function () use ($journals, $data, $lines) {
            $entry = $journals->post([
                'date' => $data['date'],
                'description' => $data['description'],
                'type' => JournalType::Manual,
                'source_number' => $data['reference'] ?? null,
            ], $lines);

            ActivityLog::record('created', "Mencatat jurnal manual {$entry->number}", $entry);

            return $entry;
        });

        return redirect()->route('journals.show', $entry)->with('success', "Jurnal {$entry->number} tersimpan.");
    }

    public function reverse(Request $request, JournalEntry $journal, JournalService $journals)
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'reason' => ['required', 'string', 'max:255'],
        ]);

        if (! $journal->canReverse()) {
            return back()->with('error', 'Hanya jurnal manual yang belum dibalik yang dapat dibatalkan. Jurnal otomatis dibatalkan dengan membatalkan dokumen asalnya.');
        }

        $reversal = DB::transaction(fn () => $journals->reverse($journal->load('lines'), $data['date'], $data['reason']));

        return redirect()->route('journals.show', $reversal)->with('success', "Jurnal {$journal->number} dibatalkan dengan jurnal pembalik {$reversal->number}.");
    }

    public function rebuild(LedgerRebuilder $rebuilder)
    {
        $count = $rebuilder->rebuild();

        ActivityLog::record('rebuilt', "Membangun ulang jurnal otomatis dari transaksi ({$count} jurnal)");

        return redirect()->route('journals.index', ['from' => JournalEntry::min('date') ?? today()->toDateString()])
            ->with('success', "Jurnal otomatis disinkronkan dari seluruh transaksi: {$count} jurnal.");
    }
}
