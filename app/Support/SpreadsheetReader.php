<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use ZipArchive;

/**
 * Pembaca file tabel tanpa paket tambahan: Excel .xlsx (lewat ZipArchive), Excel lama .xls (XlsReader),
 * dan CSV / TXT (pemisah koma, titik koma, atau tab). Hanya sheet pertama yang dibaca.
 * Mengembalikan baris berupa array nilai teks.
 */
class SpreadsheetReader
{
    /**
     * @return list<list<string>>
     */
    public static function read(string $path, ?string $originalName = null): array
    {
        $ext = strtolower(pathinfo($originalName ?? $path, PATHINFO_EXTENSION));

        $rows = match (true) {
            XlsReader::isXls($path) => XlsReader::read($path),
            self::isZip($path) => self::readXlsx($path),
            in_array($ext, ['xls', 'xlsx'], true) => throw ValidationException::withMessages(['file' => 'File Excel rusak atau bukan file Excel asli. Buka di Excel lalu simpan ulang sebagai .xlsx.']),
            default => self::readCsv($path),
        };

        // Buang baris kosong di akhir
        return array_values(array_filter($rows, fn ($r) => implode('', array_map('trim', $r)) !== ''));
    }

    /**
     * Cari baris judul kolom (dalam 15 baris pertama) yang memuat salah satu judul wajib, lalu ubah
     * baris berikutnya menjadi array asosiatif judul-ternormalisasi => nilai.
     *
     * @param  list<list<string>>  $rows
     * @param  list<string>  $required  judul kolom ternormalisasi (lihat normalize())
     * @return array{headers: list<string>, rows: list<array<string, string>>}
     */
    public static function withHeaders(array $rows, array $required): array
    {
        foreach (array_slice($rows, 0, 15, true) as $index => $row) {
            $headers = array_map([self::class, 'normalize'], $row);

            if (array_intersect($headers, $required)) {
                $data = [];
                foreach (array_slice($rows, $index + 1) as $values) {
                    $assoc = [];
                    foreach ($headers as $i => $key) {
                        if ($key === '' || array_key_exists($key, $assoc)) {
                            continue;
                        }
                        $assoc[$key] = trim((string) ($values[$i] ?? ''));
                    }
                    $data[] = $assoc;
                }

                return ['headers' => $headers, 'rows' => $data];
            }
        }

        throw ValidationException::withMessages(['file' => 'Judul kolom tidak dikenali. Pastikan baris judul kolom sesuai template yang disediakan (atau file asli ekspor Seller Center).']);
    }

    /** "No. Pesanan" → "nopesanan", "Order ID" → "orderid". */
    public static function normalize(?string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header);

        return preg_replace('/[^a-z0-9]/', '', mb_strtolower($header));
    }

    /**
     * Angka dari teks format Indonesia / internasional: "Rp 1.250.000", "1,250,000.50", "-12.500", "1.5E5".
     */
    public static function number(?string $value): float
    {
        $v = trim((string) $value);
        if ($v === '' || $v === '-') {
            return 0.0;
        }

        $negative = str_starts_with($v, '-') || (str_starts_with($v, '(') && str_ends_with($v, ')'));
        $v = preg_replace('/[^0-9.,eE]/', '', $v);

        if (preg_match('/^\d+(\.\d+)?[eE]\d+$/', $v)) {
            $n = (float) $v;
        } else {
            $v = preg_replace('/[eE]/', '', $v);
            $lastDot = strrpos($v, '.');
            $lastComma = strrpos($v, ',');

            if ($lastDot !== false && $lastComma !== false) {
                $decimal = $lastDot > $lastComma ? '.' : ',';
                $thousand = $decimal === '.' ? ',' : '.';
                $v = str_replace([$thousand, $decimal], ['', '.'], $v);
            } elseif ($lastDot !== false || $lastComma !== false) {
                $sep = $lastDot !== false ? '.' : ',';
                $parts = explode($sep, $v);
                // Pemisah ribuan bila setiap kelompok setelahnya tepat 3 digit (1.250.000 / 1,250)
                $isThousand = count($parts) > 2 || strlen(end($parts)) === 3;
                $v = $isThousand ? str_replace($sep, '', $v) : str_replace($sep, '.', $v);
            }

            $n = (float) $v;
        }

        return $negative ? -abs($n) : $n;
    }

    /**
     * Tanggal dari teks / serial Excel. $slash = urutan tanggal bergaris miring: 'dmy' (Shopee) atau 'mdy' (TikTok).
     */
    public static function date(?string $value, string $slash = 'dmy'): ?Carbon
    {
        $v = trim((string) $value);
        if ($v === '' || $v === '-' || $v === '0') {
            return null;
        }

        try {
            if (is_numeric($v) && (float) $v > 20000 && (float) $v < 80000) {
                // Serial tanggal Excel (hari sejak 1899-12-30). Hari dihitung di UTC agar tidak terpengaruh
                // selisih zona waktu lokal tahun 1899 (LMT), baru jamnya ditambahkan di zona aplikasi.
                $days = (int) floor((float) $v);
                $seconds = (int) round(((float) $v - $days) * 86400);
                $day = Carbon::create(1899, 12, 30, 0, 0, 0, 'UTC')->addDays($days)->format('Y-m-d');

                return Carbon::parse($day)->addSeconds($seconds);
            }

            if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})(.*)$#', $v, $m)) {
                [$a, $b] = [(int) $m[1], (int) $m[2]];
                $mdy = $slash === 'mdy' ? $a <= 12 : ($b > 12 && $a <= 12);
                [$day, $month] = $mdy ? [$b, $a] : [$a, $b];

                return Carbon::parse(sprintf('%04d-%02d-%02d', $m[3], $month, $day).' '.trim($m[4]));
            }

            return Carbon::parse($v);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function isZip(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        $magic = $handle ? fread($handle, 4) : '';
        $handle && fclose($handle);

        return $magic === "PK\x03\x04";
    }

    /** @return list<list<string>> */
    private static function readCsv(string $path): array
    {
        $content = (string) file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        if (! mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($content, "\n") ?: '';
        $delimiter = collect([',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")])
            ->sortDesc()->keys()->first();

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $content);
        rewind($stream);

        $rows = [];
        while (($row = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            $rows[] = array_map(fn ($v) => (string) $v, $row);
        }
        fclose($stream);

        return $rows;
    }

    /** @return list<list<string>> */
    private static function readXlsx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw ValidationException::withMessages(['file' => 'File Excel tidak dapat dibuka.']);
        }

        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $doc = simplexml_load_string($xml);
            foreach ($doc->si as $si) {
                $shared[] = self::richText($si);
            }
        }

        $sheetPath = 'xl/worksheets/sheet1.xml';
        if (($wb = $zip->getFromName('xl/workbook.xml')) !== false && ($rels = $zip->getFromName('xl/_rels/workbook.xml.rels')) !== false) {
            $workbook = simplexml_load_string($wb);
            $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $first = $workbook->sheets->sheet[0] ?? null;
            $rid = $first ? (string) $first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] : null;
            foreach (simplexml_load_string($rels)->Relationship as $rel) {
                if ((string) $rel['Id'] === $rid) {
                    $target = ltrim((string) $rel['Target'], '/');
                    $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
                }
            }
        }

        $xml = $zip->getFromName($sheetPath);
        $zip->close();

        if ($xml === false) {
            throw ValidationException::withMessages(['file' => 'Sheet pertama pada file Excel tidak ditemukan.']);
        }

        $sheet = simplexml_load_string($xml);
        $rows = [];

        foreach ($sheet->sheetData->row as $row) {
            $values = [];
            foreach ($row->c as $cell) {
                $col = self::columnIndex((string) $cell['r']);
                $type = (string) $cell['t'];
                $raw = (string) $cell->v;
                $value = match ($type) {
                    's' => $shared[(int) $raw] ?? '',
                    'inlineStr' => self::richText($cell->is),
                    'b' => $raw === '1' ? 'TRUE' : 'FALSE',
                    'e' => '',
                    'str' => $raw,
                    default => is_numeric($raw) ? XlsReader::numeric((float) $raw) : $raw,
                };
                $values[$col] = $value;
            }

            $line = [];
            $max = $values ? max(array_keys($values)) : -1;
            for ($i = 0; $i <= $max; $i++) {
                $line[] = $values[$i] ?? '';
            }
            $rows[] = $line;
        }

        return $rows;
    }

    /** Teks sel biasa (<t>) atau rich text (<r><t>…). */
    private static function richText(?\SimpleXMLElement $node): string
    {
        if (! $node) {
            return '';
        }
        if (isset($node->t)) {
            return (string) $node->t;
        }
        $text = '';
        foreach ($node->r as $r) {
            $text .= (string) $r->t;
        }

        return $text;
    }

    private static function columnIndex(string $ref): int
    {
        $letters = preg_replace('/\d/', '', $ref);
        $index = 0;
        foreach (str_split($letters) as $char) {
            $index = $index * 26 + (ord($char) - 64);
        }

        return max(0, $index - 1);
    }
}
