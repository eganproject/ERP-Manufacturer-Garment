<?php

namespace App\Services\Import;

use App\Enums\JournalType;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Services\Accounting\JournalService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Jurnal umum dari Excel, terutama untuk SALDO AWAL akun (kas, bank, piutang/hutang lama, modal) saat mulai
 * memakai sistem. Baris dengan Tanggal + Keterangan + No. Referensi sama = satu jurnal; tiap jurnal harus seimbang.
 */
class JournalImporter extends Importer
{
    public function __construct(private JournalService $journals) {}

    public function key(): string
    {
        return 'journals';
    }

    public function label(): string
    {
        return 'Jurnal / Saldo Awal Akun';
    }

    public function description(): string
    {
        return 'Jurnal umum dari Excel, mis. saldo awal kas, bank, piutang & hutang lama, dan modal saat mulai memakai sistem.';
    }

    public function ability(): string
    {
        return 'accounting.manage';
    }

    public function indexRoute(): ?string
    {
        return 'journals.index';
    }

    protected function uniqueKey(): ?string
    {
        return null;
    }

    public function columns(): array
    {
        return [
            'date' => ['Tanggal', ['type' => 'date', 'required' => true, 'example' => '30/09/2026', 'tip' => 'Tanggal jurnal. Untuk saldo awal pakai tanggal terakhir sebelum mulai memakai sistem.']],
            'description' => ['Keterangan Jurnal', ['required' => true, 'example' => 'Saldo awal per 30 Sep 2026', 'tip' => 'Baris dengan Tanggal, Keterangan & No. Referensi yang sama digabung menjadi satu jurnal.']],
            'reference' => ['No. Referensi', ['max' => 50, 'example' => 'SA-2026']],
            'account' => ['Kode Akun', ['required' => true, 'max' => 30, 'example' => '1101', 'tip' => 'Kode akun di Bagan Akun (bukan akun induk). Lihat menu Akuntansi → Bagan Akun.']],
            'line_description' => ['Keterangan Baris', ['example' => 'Kas kecil']],
            'debit' => ['Debit', ['type' => 'number', 'min' => 0, 'example' => 5000000]],
            'credit' => ['Kredit', ['type' => 'number', 'min' => 0, 'example' => '']],
        ];
    }

    protected function check(array $row, array &$context): array
    {
        $errors = [];
        $account = Account::where('code', $row['account'])->first();
        if (! $account) {
            $errors[] = "akun dengan kode \"{$row['account']}\" tidak ada di Bagan Akun";
        } elseif ($account->is_header || ! $account->is_active) {
            $errors[] = "akun {$account->code} adalah akun induk / nonaktif, pilih akun rinciannya";
        }

        if (((float) $row['debit'] > 0) === ((float) $row['credit'] > 0)) {
            $errors[] = 'isi Debit atau Kredit (salah satu, lebih dari 0)';
        }

        return $errors;
    }

    protected function checkAll(array $rows): array
    {
        $errors = [];
        foreach ($this->groups($rows) as $group) {
            $lines = array_keys($group);
            $debit = round(array_sum(array_map(fn ($r) => (float) $r['debit'], $group)), 2);
            $credit = round(array_sum(array_map(fn ($r) => (float) $r['credit'], $group)), 2);
            $first = reset($group);

            if (count($group) < 2) {
                $errors[] = "Baris {$lines[0]}: jurnal \"{$first['description']}\" minimal 2 baris (debit & kredit).";
            } elseif (abs($debit - $credit) > 0.005) {
                $errors[] = 'Baris '.implode(', ', $lines).": jurnal \"{$first['description']}\" tidak seimbang (debit ".rupiah($debit).', kredit '.rupiah($credit).').';
            }
        }

        return $errors;
    }

    protected function saveAll(array $rows): array
    {
        $count = 0;
        $accounts = Account::whereIn('code', array_column($rows, 'account'))->pluck('id', 'code');

        foreach ($this->groups($rows) as $group) {
            $first = reset($group);
            $line = array_key_first($group);

            try {
                $entry = $this->journals->post([
                    'date' => Carbon::parse($first['date']),
                    'description' => $first['description'],
                    'type' => JournalType::Manual,
                    'source_number' => $first['reference'],
                ], array_map(fn ($r) => [
                    'account' => (int) $accounts[$r['account']],
                    'debit' => round((float) $r['debit'], 2),
                    'credit' => round((float) $r['credit'], 2),
                    'description' => $r['line_description'],
                ], array_values($group)));
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(['file' => "Baris {$line}: ".collect($e->errors())->flatten()->first()]);
            }

            if ($entry) {
                ActivityLog::record('created', "Mencatat jurnal manual {$entry->number} (impor Excel)", $entry);
                $count++;
            }
        }

        return ['created' => $count, 'updated' => 0, 'rows' => count($rows)];
    }

    protected function save(array $row): string
    {
        throw new \LogicException('Jurnal disimpan per kelompok lewat saveAll().');
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<array<int, array<string, mixed>>>
     */
    private function groups(array $rows): array
    {
        $groups = [];
        foreach ($rows as $line => $row) {
            $groups[$row['date'].'|'.mb_strtolower($row['description']).'|'.mb_strtolower((string) $row['reference'])][$line] = $row;
        }

        return array_values($groups);
    }

    public function notes(): array
    {
        return [
            'Satu jurnal = beberapa baris dengan Tanggal, Keterangan Jurnal & No. Referensi yang sama; total Debit harus sama dengan total Kredit.',
            'Saldo awal persediaan sebaiknya lewat Impor Stok Awal (agar stok & nilai persediaan sinkron), dan aset tetap lama lewat menu Aset Tetap (perolehan: saldo awal).',
            'Lawan saldo awal biasanya akun Ekuitas Saldo Awal (lihat Pemetaan Akun). Hasil impor = jurnal manual dengan nomor JU-, bisa dibatalkan dengan jurnal pembalik.',
        ];
    }
}
