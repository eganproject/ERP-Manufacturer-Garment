<?php

namespace App\Services\Import;

use App\Models\ActivityLog;
use App\Support\SpreadsheetReader;
use App\Support\XlsxWriter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Dasar impor data dari Excel (.xlsx / .xls) atau CSV.
 *
 * Alur: baca sheet pertama → cari baris judul kolom → ubah tiap sel sesuai tipe kolom → validasi SEMUA baris
 * (termasuk referensi & duplikat) → bila ada satu kesalahan pun, tidak ada yang disimpan (pesan per baris);
 * bila bersih, semua baris disimpan dalam satu transaksi. Kode / SKU yang sudah ada = diperbarui, sel kosong
 * pada data lama = nilai lama dipertahankan.
 */
abstract class Importer
{
    public const MAX_ERRORS = 50;

    /** Kunci di URL, mis. "customers". */
    abstract public function key(): string;

    abstract public function label(): string;

    abstract public function description(): string;

    /** Hak akses (Gate) yang dibutuhkan. */
    abstract public function ability(): string;

    /** Route daftar data hasil impor (untuk tombol "Lihat data"). */
    abstract public function indexRoute(): ?string;

    /**
     * Definisi kolom: key => [header, opsi]. Opsi: required (bool), type (text|number|integer|date|bool|enum),
     * max (panjang teks), options (enum: label/alias → nilai), tip (penjelasan), example (contoh), aliases (judul lain).
     *
     * @return array<string, array{0: string, 1?: array<string, mixed>}>
     */
    abstract public function columns(): array;

    /**
     * Validasi tambahan per baris (referensi, aturan bisnis). Kembalikan daftar pesan kesalahan.
     * $context dipakai bersama antar baris (mis. mendeteksi duplikat di file).
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $context
     * @return list<string>
     */
    protected function check(array $row, array &$context): array
    {
        return [];
    }

    /**
     * Simpan satu baris (sudah tervalidasi, di dalam transaksi). Kembalikan 'created' atau 'updated'.
     *
     * @param  array<string, mixed>  $row
     */
    abstract protected function save(array $row): string;

    /**
     * Validasi lintas baris (mis. jurnal harus seimbang per kelompok). Pesan sudah berawalan "Baris N: ".
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return list<string>
     */
    protected function checkAll(array $rows): array
    {
        return [];
    }

    /**
     * Simpan semua baris (di dalam transaksi). Bawaan: save() per baris.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{created: int, updated: int, rows: int}
     */
    protected function saveAll(array $rows): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'rows' => count($rows)];
        foreach ($rows as $line => $row) {
            try {
                $counts[$this->save($row)]++;
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(['file' => "Baris {$line}: ".collect($e->errors())->flatten()->first()]);
            }
        }

        return $counts;
    }

    /**
     * Data yang ada sekarang dalam susunan kolom template (untuk ubah massal). null = tidak tersedia.
     *
     * @return iterable<list<mixed>>|null
     */
    public function exportRows(): ?iterable
    {
        return null;
    }

    /** @return list<string> */
    public function notes(): array
    {
        return [];
    }

    /** Kunci kolom yang nilainya harus unik di dalam file. */
    protected function uniqueKey(): ?string
    {
        return 'code';
    }

    // ------------------------------------------------------------------

    /**
     * @return array{created: int, updated: int, rows: int}
     */
    public function import(UploadedFile|string $file, ?string $originalName = null): array
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        $name = $file instanceof UploadedFile ? $file->getClientOriginalName() : ($originalName ?? $file);

        $rows = $this->parse(SpreadsheetReader::read($path, $name));

        $result = DB::transaction(fn () => $this->saveAll($rows));

        ActivityLog::record('import', "Impor {$this->label()} dari file {$name}: {$result['created']} baru, {$result['updated']} diperbarui.");

        return $result;
    }

    /**
     * Ubah baris mentah menjadi baris bertipe & tervalidasi, dikunci nomor baris di file.
     *
     * @param  list<list<string>>  $raw
     * @return array<int, array<string, mixed>>
     */
    public function parse(array $raw): array
    {
        $columns = $this->columns();
        $required = collect($columns)->filter(fn ($c) => $c[1]['required'] ?? false)->keys();
        $aliases = [];
        foreach ($columns as $key => [$header, $opt]) {
            foreach (array_merge([$header], $opt['aliases'] ?? []) as $alias) {
                $aliases[SpreadsheetReader::normalize($alias)] = $key;
            }
        }

        // Cari baris judul (15 baris pertama) yang memuat semua kolom wajib
        $headerIndex = null;
        $map = [];
        foreach (array_slice($raw, 0, 15, true) as $index => $line) {
            $found = [];
            foreach ($line as $i => $cell) {
                $key = $aliases[SpreadsheetReader::normalize($cell)] ?? null;
                if ($key && ! in_array($key, $found, true)) {
                    $found[$i] = $key;
                }
            }
            if ($found && $required->diff($found)->isEmpty()) {
                $headerIndex = $index;
                $map = $found;
                break;
            }
        }

        if ($headerIndex === null) {
            $names = $required->map(fn ($k) => '"'.$columns[$k][0].'"')->implode(', ');
            throw ValidationException::withMessages(['file' => "Judul kolom tidak ditemukan. Baris judul harus memuat kolom {$names}. Gunakan template Excel yang disediakan."]);
        }

        $rows = [];
        $errors = [];
        $context = [];
        $unique = $this->uniqueKey();
        $seen = [];

        foreach (array_slice($raw, $headerIndex + 1, null, true) as $index => $line) {
            $lineNo = $index + 1;
            $values = [];
            foreach ($map as $i => $key) {
                $values[$key] = trim((string) ($line[$i] ?? ''));
            }

            if (implode('', $values) === '') {
                continue;
            }

            $row = [];
            $rowErrors = [];
            foreach ($columns as $key => [$header, $opt]) {
                $text = $values[$key] ?? '';
                [$value, $error] = $this->cast($text, $opt, $header);
                if ($error) {
                    $rowErrors[] = $error;
                } elseif (($opt['required'] ?? false) && ($value === null || $value === '')) {
                    $rowErrors[] = "kolom \"{$header}\" wajib diisi";
                }
                $row[$key] = $value;
            }

            if (! $rowErrors && $unique && ($row[$unique] ?? null) !== null) {
                $k = mb_strtolower((string) $row[$unique]);
                if (isset($seen[$k])) {
                    $rowErrors[] = "\"{$row[$unique]}\" sudah ada di baris {$seen[$k]} (tidak boleh dobel dalam satu file)";
                }
                $seen[$k] = $lineNo;
            }

            if (! $rowErrors) {
                $rowErrors = $this->check($row, $context);
            }

            foreach ($rowErrors as $message) {
                $errors[] = "Baris {$lineNo}: {$message}.";
            }

            $rows[$lineNo] = $row;
        }

        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'File tidak berisi data (hanya judul kolom). Isi data di bawah baris judul.']);
        }

        if (! $errors) {
            $errors = $this->checkAll($rows);
        }

        if ($errors) {
            $total = count($errors);
            $shown = array_slice($errors, 0, self::MAX_ERRORS);
            if ($total > self::MAX_ERRORS) {
                $shown[] = '… dan '.($total - self::MAX_ERRORS).' kesalahan lain.';
            }
            array_unshift($shown, "Tidak ada data yang disimpan karena ditemukan {$total} kesalahan. Perbaiki file lalu unggah ulang:");

            throw ValidationException::withMessages(['file' => $shown]);
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $opt
     * @return array{0: mixed, 1: ?string}
     */
    private function cast(string $text, array $opt, string $header): array
    {
        if ($text === '' || ($text === '-' && ($opt['type'] ?? 'text') !== 'text')) {
            return [null, null];
        }

        switch ($opt['type'] ?? 'text') {
            case 'number':
            case 'integer':
                $plain = trim(preg_replace('/^rp\.?/i', '', $text));
                if (! preg_match('/\d/', $plain) || ! preg_match('/^[\s\d.,+\-()eE]+$/', $plain)) {
                    return [null, "kolom \"{$header}\" harus berupa angka (isi: \"{$text}\")"];
                }
                $number = SpreadsheetReader::number($text);
                if (($opt['type'] ?? '') === 'integer') {
                    $number = (int) round($number);
                }
                if (isset($opt['min']) && $number < $opt['min']) {
                    return [null, "kolom \"{$header}\" minimal {$opt['min']}"];
                }

                return [$number, null];

            case 'date':
                $date = SpreadsheetReader::date($text);

                return $date ? [$date->format('Y-m-d'), null] : [null, "kolom \"{$header}\" bukan tanggal yang valid (isi: \"{$text}\"; contoh 31/12/2026)"];

            case 'bool':
                $v = SpreadsheetReader::normalize($text);
                if (in_array($v, ['ya', 'y', 'yes', '1', 'true', 'aktif', 'benar'], true)) {
                    return [true, null];
                }
                if (in_array($v, ['tidak', 't', 'no', 'n', '0', 'false', 'nonaktif', 'salah'], true)) {
                    return [false, null];
                }

                return [null, "kolom \"{$header}\" diisi Ya atau Tidak (isi: \"{$text}\")"];

            case 'enum':
                $key = SpreadsheetReader::normalize($text);
                foreach ($opt['options'] as $label => $value) {
                    if (SpreadsheetReader::normalize((string) $label) === $key) {
                        return [$value, null];
                    }
                }

                return [null, "kolom \"{$header}\" tidak dikenali (isi: \"{$text}\"; pilihan: ".implode(' / ', array_unique(array_map('strval', $opt['choices'] ?? array_keys($opt['options'])))).')'];

            default:
                $max = $opt['max'] ?? 255;
                if (mb_strlen($text) > $max) {
                    return [null, "kolom \"{$header}\" maksimal {$max} karakter"];
                }

                return [$text, null];
        }
    }

    // ------------------------------------------------------------------ template

    public function template(bool $withData = false): BinaryFileResponse
    {
        $columns = $this->columns();
        $headers = [];
        $required = [];
        $text = [];
        $example = [];
        $guide = [['Kolom', 'Wajib?', 'Keterangan', 'Contoh']];
        $i = 0;
        foreach ($columns as [$header, $opt]) {
            $isRequired = $opt['required'] ?? false;
            $headers[] = $header.($isRequired ? '*' : '');
            if ($isRequired) {
                $required[] = $i;
            }
            if (($opt['type'] ?? 'text') === 'text') {
                $text[] = $i;
            }
            $example[] = $opt['example'] ?? '';
            $guide[] = [$header, $isRequired ? 'Wajib' : 'Opsional', $this->describe($opt), is_bool($opt['example'] ?? null) ? ($opt['example'] ? 'Ya' : 'Tidak') : ($opt['example'] ?? '')];
            $i++;
        }

        $data = [$headers];
        if ($withData) {
            foreach ($this->exportRows() ?? [] as $row) {
                $data[] = $row;
            }
        }

        $notes = array_merge([
            "Impor {$this->label()}",
            '1. Isi data di sheet "Data" mulai baris ke-2. Jangan ubah judul kolom (kolom bertanda * dan berwarna kuning wajib diisi).',
            '2. Satu baris = satu data. Baris kosong diabaikan. Contoh pengisian ada di sheet "Contoh" (tidak ikut terimpor).',
            '3. Kode / SKU yang sudah ada di sistem akan DIPERBARUI; sel yang dikosongkan tidak mengubah nilai lama. Kode baru = data baru.',
            '4. Bila ada satu baris yang salah, tidak ada data yang disimpan. Sistem menampilkan nomor baris & penyebabnya.',
            '5. Simpan sebagai Excel (.xlsx atau .xls) atau CSV, lalu unggah di menu Impor Data.',
        ], $this->notes());

        return (new XlsxWriter)
            ->sheet('Data', $data, required: $required, textColumns: $text)
            ->sheet('Contoh', [$headers, $example], required: $required, textColumns: $text)
            ->sheet('Petunjuk', array_merge(array_map(fn ($n) => [$n], $notes), [[''], ...$guide]), header: false)
            ->download(($withData ? 'data-' : 'template-impor-').$this->key().'.xlsx');
    }

    /** @param array<string, mixed> $opt */
    public function describe(array $opt): string
    {
        $type = match ($opt['type'] ?? 'text') {
            'number' => 'Angka.',
            'integer' => 'Angka bulat.',
            'date' => 'Tanggal (format tanggal Excel atau 31/12/2026).',
            'bool' => 'Ya / Tidak.',
            'enum' => 'Pilihan: '.implode(' / ', array_unique(array_map('strval', $opt['choices'] ?? array_keys($opt['options'])))).'.',
            default => 'Teks.',
        };

        return trim(($opt['tip'] ?? '').' '.$type);
    }
}
