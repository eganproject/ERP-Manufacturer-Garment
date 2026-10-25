<?php

namespace App\Services;

use App\Enums\CashTransactionType;
use App\Enums\PaymentStatus;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\CashTransaction;
use App\Services\Accounting\AutoJournal;
use App\Services\Accounting\JournalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kas & bank di luar pelunasan invoice/tagihan: kas masuk (KM-), kas keluar (KK-), transfer antar rekening (TRF-).
 */
class CashBankService
{
    public function __construct(private AutoJournal $journal) {}

    /**
     * @param  array{date: string, cash_account_id: int|string, to_account_id?: int|string|null, amount?: float|string|null, party_name?: string|null, reference?: string|null, description: string}  $data
     * @param  list<array{account_id: int|string, description?: string|null, amount: float|string|null}>  $lines  baris lawan (kas masuk/keluar)
     */
    public function record(CashTransactionType $type, array $data, array $lines = []): CashTransaction
    {
        $cash = Account::cash()->where('is_active', true)->find($data['cash_account_id']);

        if (! $cash) {
            throw ValidationException::withMessages(['cash_account_id' => 'Pilih rekening kas/bank yang aktif.']);
        }

        if ($type === CashTransactionType::Transfer) {
            $to = Account::cash()->where('is_active', true)->find($data['to_account_id'] ?? null);
            $amount = round((float) ($data['amount'] ?? 0), 2);

            if (! $to || $to->id === $cash->id) {
                throw ValidationException::withMessages(['to_account_id' => 'Pilih rekening tujuan yang berbeda dari rekening asal.']);
            }
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'Jumlah transfer harus lebih dari 0.']);
            }
            $lines = [];
        } else {
            $lines = $this->normalizeLines($lines);
            $amount = round(array_sum(array_column($lines, 'amount')), 2);
        }

        return DB::transaction(function () use ($type, $data, $lines, $amount, $cash) {
            $date = Carbon::parse($data['date']);

            $transaction = CashTransaction::create([
                'number' => DocumentNumber::generate(CashTransaction::class, $type->prefix(), $date),
                'type' => $type,
                'date' => $date,
                'cash_account_id' => $cash->id,
                'to_account_id' => $type === CashTransactionType::Transfer ? (int) $data['to_account_id'] : null,
                'amount' => $amount,
                'party_name' => $data['party_name'] ?? null,
                'reference' => $data['reference'] ?? null,
                'description' => $data['description'],
                'status' => PaymentStatus::Posted,
                'created_by' => auth()->id(),
            ]);
            $transaction->lines()->createMany($lines);

            $this->journal->cashTransaction($transaction);

            ActivityLog::record('created', "Mencatat {$type->label()} {$transaction->number} sebesar ".rupiah($amount), $transaction);

            return $transaction;
        });
    }

    public function cancel(CashTransaction $transaction, string $reason): void
    {
        if ($transaction->isCancelled()) {
            throw ValidationException::withMessages(['status' => 'Bukti kas ini sudah dibatalkan.']);
        }

        DB::transaction(function () use ($transaction, $reason) {
            $transaction->update(['status' => PaymentStatus::Cancelled, 'cancel_reason' => $reason]);
            $this->journal->reverse($transaction, "{$transaction->type->label()} dibatalkan – {$reason}");

            ActivityLog::record('cancelled', "Membatalkan {$transaction->type->label()}: {$reason}", $transaction);
        });
    }

    /**
     * @return list<array{account_id: int, description: string|null, amount: float}>
     */
    private function normalizeLines(array $lines): array
    {
        $rows = [];

        foreach (array_values($lines) as $i => $line) {
            $amount = round((float) ($line['amount'] ?? 0), 2);

            if (empty($line['account_id']) && $amount <= 0) {
                continue;
            }

            $account = Account::find($line['account_id'] ?? null);

            if (! $account) {
                throw ValidationException::withMessages(["lines.{$i}.account_id" => 'Baris '.($i + 1).': pilih akun.']);
            }
            if ($account->is_cash) {
                throw ValidationException::withMessages(["lines.{$i}.account_id" => 'Baris '.($i + 1).": {$account->name} adalah rekening kas/bank. Gunakan Transfer Antar Rekening."]);
            }
            JournalService::assertPostable($account);

            if ($amount <= 0) {
                throw ValidationException::withMessages(["lines.{$i}.amount" => 'Baris '.($i + 1).': jumlah harus lebih dari 0.']);
            }

            $rows[] = ['account_id' => $account->id, 'description' => ($line['description'] ?? null) ?: null, 'amount' => $amount];
        }

        if (empty($rows)) {
            throw ValidationException::withMessages(['lines' => 'Isi minimal satu baris akun & jumlah.']);
        }

        return $rows;
    }
}
