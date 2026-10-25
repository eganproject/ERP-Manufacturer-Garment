<?php

namespace App\Services\Accounting;

use App\Enums\JournalType;
use App\Models\Account;
use App\Models\AccountingPeriod;
use App\Models\ActivityLog;
use App\Models\JournalEntry;
use App\Services\DocumentNumber;
use App\Support\LedgerAccounts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Mesin jurnal umum. Semua jurnal (otomatis maupun manual) diposting lewat sini:
 * memeriksa keseimbangan debit-kredit, akun detail, dan periode yang sudah ditutup.
 * Harus dipanggil di dalam DB::transaction.
 */
class JournalService
{
    /** Saat membangun ulang jurnal, periode tertutup tidak diperiksa. */
    public bool $ignoreLock = false;

    public function __construct(private LedgerAccounts $accounts) {}

    /**
     * @param  list<array{account: string|int, debit?: float, credit?: float, amount?: float, description?: string|null, reference?: Model|null}>  $lines
     *                                                                                                                                                     account = peran (lihat LedgerAccounts) atau id akun; amount positif = debit, negatif = kredit
     * @param  array{date: \DateTimeInterface|string, description: string, type?: JournalType, source?: Model|null, source_number?: string|null, event?: string|null, reversal_of_id?: int|null}  $data
     */
    public function post(array $data, array $lines): ?JournalEntry
    {
        $date = Carbon::parse($data['date'])->startOfDay();
        $type = $data['type'] ?? JournalType::Auto;
        $rows = $this->normalize($lines);

        if (empty($rows)) {
            return null; // tidak ada nilai (mis. barang dengan harga pokok 0)
        }

        $debit = round(array_sum(array_column($rows, 'debit')), 2);
        $credit = round(array_sum(array_column($rows, 'credit')), 2);

        if (abs($debit - $credit) > 0.005) {
            if ($type === JournalType::Manual) {
                throw ValidationException::withMessages(['lines' => 'Total debit ('.rupiah($debit).') dan kredit ('.rupiah($credit).') harus sama.']);
            }

            throw new LogicException("Jurnal otomatis tidak seimbang: debit {$debit}, kredit {$credit} ({$data['description']}).");
        }

        $this->ensureOpen($date);

        $source = $data['source'] ?? null;

        $entry = JournalEntry::create([
            'number' => DocumentNumber::generate(JournalEntry::class, $type->prefix(), $date),
            'date' => $date,
            'type' => $type,
            'description' => mb_substr($data['description'], 0, 255),
            'source_type' => $source?->getMorphClass() ?? ($data['source_type'] ?? null),
            'source_id' => $source?->getKey() ?? ($data['source_id'] ?? null),
            'source_number' => $data['source_number'] ?? $source?->getAttribute('number'),
            'event' => $data['event'] ?? null,
            'total' => $debit,
            'reversal_of_id' => $data['reversal_of_id'] ?? null,
            'created_by' => auth()->id(),
        ]);
        $entry->lines()->createMany($rows);

        return $entry;
    }

    /** Balik jurnal: buat jurnal lawan bertanggal $date dan tandai jurnal asal sudah dibalik. */
    public function reverse(JournalEntry $entry, \DateTimeInterface|string|null $date = null, ?string $reason = null): ?JournalEntry
    {
        if ($entry->isReversed() || $entry->isReversal()) {
            return null;
        }

        $date = Carbon::parse($date ?? today());
        if ($date->lt($entry->date)) {
            $date = $entry->date->copy();
        }

        $lines = $entry->lines->map(fn ($line) => [
            'account' => $line->account_id,
            'debit' => (float) $line->credit,
            'credit' => (float) $line->debit,
            'description' => $line->description,
            'reference_type' => $line->reference_type,
            'reference_id' => $line->reference_id,
        ])->all();

        $reversal = $this->post([
            'date' => $date,
            'description' => 'Pembalik '.$entry->number.': '.($reason ?: $entry->description),
            'type' => $entry->type,
            'source_type' => $entry->source_type,
            'source_id' => $entry->source_id,
            'source_number' => $entry->source_number,
            'event' => $entry->event,
            'reversal_of_id' => $entry->id,
        ], $lines);

        $entry->update(['reversed_at' => now()]);

        if ($entry->type === JournalType::Manual) {
            ActivityLog::record('reversed', "Membalik jurnal {$entry->number}".($reason ? ": {$reason}" : ''), $entry);
        }

        return $reversal;
    }

    /**
     * Balik semua jurnal otomatis yang masih berlaku dari dokumen (opsional hanya event tertentu).
     */
    public function reverseSource(Model $source, ?string $event = null, \DateTimeInterface|string|null $date = null, ?string $reason = null): void
    {
        $entries = JournalEntry::with('lines')
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->when($event, fn ($q) => $q->where('event', $event))
            ->effective()
            ->orderBy('id')
            ->get();

        foreach ($entries as $entry) {
            $this->reverse($entry, $date, $reason);
        }
    }

    public function ensureOpen(\DateTimeInterface $date): void
    {
        if (! $this->ignoreLock && AccountingPeriod::isLocked($date)) {
            throw ValidationException::withMessages([
                'date' => 'Periode '.Carbon::parse($date)->translatedFormat('F Y').' sudah ditutup (tutup buku s.d. '.tanggal(AccountingPeriod::lockedUntil()).'). Gunakan tanggal setelah periode tertutup atau buka kembali periodenya.',
            ]);
        }
    }

    /**
     * Ubah baris menjadi debit/kredit positif, buang baris bernilai nol, dan gabungkan baris yang sama.
     *
     * @return list<array<string, mixed>>
     */
    private function normalize(array $lines): array
    {
        $rows = [];

        foreach ($lines as $line) {
            $amount = array_key_exists('amount', $line)
                ? (float) $line['amount']
                : (float) ($line['debit'] ?? 0) - (float) ($line['credit'] ?? 0);
            $amount = round($amount, 2);

            if (abs($amount) < 0.005) {
                continue;
            }

            $accountId = is_int($line['account']) ? $line['account'] : $this->accounts->id($line['account']);
            $reference = $line['reference'] ?? null;
            $refType = $reference?->getMorphClass() ?? ($line['reference_type'] ?? null);
            $refId = $reference?->getKey() ?? ($line['reference_id'] ?? null);
            $description = $line['description'] ?? null;
            $key = implode('|', [$accountId, $refType, $refId, $description, $amount > 0 ? 'D' : 'C']);

            if (isset($rows[$key])) {
                $rows[$key][$amount > 0 ? 'debit' : 'credit'] = round($rows[$key][$amount > 0 ? 'debit' : 'credit'] + abs($amount), 2);

                continue;
            }

            $rows[$key] = [
                'account_id' => $accountId,
                'description' => $description ? mb_substr($description, 0, 255) : null,
                'debit' => $amount > 0 ? $amount : 0,
                'credit' => $amount < 0 ? abs($amount) : 0,
                'reference_type' => $refType,
                'reference_id' => $refId,
            ];
        }

        // Debit di atas, kredit di bawah (urutan lazim jurnal)
        $rows = array_values($rows);
        usort($rows, fn ($a, $b) => ($b['debit'] > 0) <=> ($a['debit'] > 0));

        return $rows;
    }

    /** Pastikan akun bisa dipakai jurnal manual. */
    public static function assertPostable(Account $account): void
    {
        if ($account->is_header || ! $account->is_active) {
            throw ValidationException::withMessages(['lines' => "Akun {$account->display_name} adalah akun induk / nonaktif dan tidak dapat dijurnal."]);
        }
    }
}
