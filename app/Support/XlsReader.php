<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * Pembaca file Excel lama (.xls, Excel 97–2003 / BIFF8) tanpa paket tambahan.
 *
 * File .xls adalah "compound document" (OLE2) berisi stream "Workbook". Yang dibaca hanya nilai sel
 * sheet pertama (teks, angka, hasil rumus, boolean); format & gaya diabaikan.
 */
class XlsReader
{
    private const END_OF_CHAIN = -2;

    private string $data;

    private int $sectorSize;

    private int $miniSectorSize;

    /** @var list<int> */
    private array $fat = [];

    /** @var list<int> */
    private array $miniFat = [];

    private string $miniStream = '';

    /** @var list<string> */
    private array $sst = [];

    /**
     * @return list<list<string>>
     */
    public static function read(string $path): array
    {
        return (new self)->parse((string) file_get_contents($path));
    }

    public static function isXls(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        $magic = $handle ? fread($handle, 8) : '';
        $handle && fclose($handle);

        return $magic === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    }

    /** @return list<list<string>> */
    private function parse(string $data): array
    {
        if (strlen($data) < 512 || substr($data, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            $this->fail('File .xls tidak valid.');
        }

        $this->data = $data;
        $workbook = $this->workbookStream();

        return $this->readSheet($workbook);
    }

    // ---------------------------------------------------------------- OLE2

    private function workbookStream(): string
    {
        $this->sectorSize = 1 << $this->u16(0x1E);
        $this->miniSectorSize = 1 << $this->u16(0x20);
        $fatSectors = $this->u32(0x2C);
        $firstDir = $this->s32(0x30);
        $miniCutoff = $this->u32(0x38);
        $firstMiniFat = $this->s32(0x3C);
        $firstDifat = $this->s32(0x44);
        $difatCount = $this->u32(0x48);

        if ($this->sectorSize < 512 || $this->sectorSize > 4096) {
            $this->fail('File .xls tidak valid.');
        }

        // Daftar sektor FAT: 109 entri di header + rantai sektor DIFAT
        $fatList = [];
        for ($i = 0; $i < 109 && count($fatList) < $fatSectors; $i++) {
            $fatList[] = $this->s32(0x4C + $i * 4);
        }
        $perDifat = intdiv($this->sectorSize, 4) - 1;
        for ($sector = $firstDifat, $n = 0; $sector >= 0 && $n < $difatCount && count($fatList) < $fatSectors; $n++) {
            $offset = $this->sectorOffset($sector);
            for ($i = 0; $i < $perDifat && count($fatList) < $fatSectors; $i++) {
                $fatList[] = $this->s32($offset + $i * 4);
            }
            $sector = $this->s32($offset + $perDifat * 4);
        }

        foreach ($fatList as $sector) {
            $offset = $this->sectorOffset($sector);
            for ($i = 0; $i < $this->sectorSize; $i += 4) {
                $this->fat[] = $this->s32($offset + $i);
            }
        }

        $directory = $this->chain($firstDir);
        $entries = [];
        for ($i = 0; $i + 128 <= strlen($directory); $i += 128) {
            $nameLength = unpack('v', substr($directory, $i + 0x40, 2))[1];
            $name = $nameLength > 2 ? mb_convert_encoding(substr($directory, $i, $nameLength - 2), 'UTF-8', 'UTF-16LE') : '';
            $entries[] = [
                'name' => $name,
                'type' => ord($directory[$i + 0x42]),
                'start' => unpack('l', substr($directory, $i + 0x74, 4))[1],
                'size' => unpack('V', substr($directory, $i + 0x78, 4))[1],
            ];
        }

        $root = collect($entries)->firstWhere('type', 5);
        $book = collect($entries)->first(fn ($e) => $e['type'] === 2 && in_array(strtolower($e['name']), ['workbook', 'book'], true));

        if (! $book) {
            $this->fail('File .xls tidak berisi data workbook (mungkin terenkripsi / berpassword).');
        }

        if ($book['size'] < $miniCutoff && $root) {
            $this->miniStream = $this->chain($root['start'], $root['size']);
            if ($firstMiniFat >= 0) {
                $raw = $this->chain($firstMiniFat);
                foreach (unpack('l*', $raw) ?: [] as $value) {
                    $this->miniFat[] = $value;
                }
            }

            return $this->miniChain($book['start'], $book['size']);
        }

        return $this->chain($book['start'], $book['size']);
    }

    private function chain(int $sector, ?int $size = null): string
    {
        $out = '';
        $guard = 0;
        while ($sector >= 0 && $guard++ < 1_000_000) {
            $out .= substr($this->data, $this->sectorOffset($sector), $this->sectorSize);
            $sector = $this->fat[$sector] ?? self::END_OF_CHAIN;
            if ($size !== null && strlen($out) >= $size) {
                break;
            }
        }

        return $size !== null ? substr($out, 0, $size) : $out;
    }

    private function miniChain(int $sector, int $size): string
    {
        $out = '';
        $guard = 0;
        while ($sector >= 0 && strlen($out) < $size && $guard++ < 1_000_000) {
            $out .= substr($this->miniStream, $sector * $this->miniSectorSize, $this->miniSectorSize);
            $sector = $this->miniFat[$sector] ?? self::END_OF_CHAIN;
        }

        return substr($out, 0, $size);
    }

    private function sectorOffset(int $sector): int
    {
        return ($sector + 1) * $this->sectorSize;
    }

    // ---------------------------------------------------------------- BIFF8

    /** @return list<list<string>> */
    private function readSheet(string $stream): array
    {
        $length = strlen($stream);
        $records = [];
        for ($pos = 0; $pos + 4 <= $length;) {
            ['t' => $type, 'l' => $len] = unpack('vt/vl', substr($stream, $pos, 4));
            $records[] = [$type, $pos, substr($stream, $pos + 4, $len)];
            $pos += 4 + $len;
        }

        // Globals: BOF … EOF berisi SST & daftar sheet
        $sheetOffset = null;
        $count = count($records);
        for ($i = 0; $i < $count; $i++) {
            [$type, $pos, $body] = $records[$i];

            if ($i === 0 && ($type !== 0x0809 || unpack('v', substr($body, 0, 2))[1] !== 0x0600)) {
                $this->fail('Format .xls terlalu lama (sebelum Excel 97). Simpan ulang sebagai .xlsx.');
            }

            if ($type === 0x002F) {
                $this->fail('File .xls diproteksi password. Buka di Excel, hapus password, lalu simpan ulang.');
            }

            if ($type === 0x0085 && $sheetOffset === null && strlen($body) >= 6 && ord($body[5]) === 0) {
                $sheetOffset = unpack('V', substr($body, 0, 4))[1];
            }

            if ($type === 0x00FC) {
                $parts = [$body];
                while (isset($records[$i + 1]) && $records[$i + 1][0] === 0x003C) {
                    $parts[] = $records[++$i][2];
                }
                $this->readSst($parts);
            }

            if ($type === 0x000A) {
                break;
            }
        }

        if ($sheetOffset === null) {
            $this->fail('Sheet pertama pada file Excel tidak ditemukan.');
        }

        $cells = [];
        $pendingFormula = null;
        $started = false;
        $depth = 0;

        foreach ($records as [$type, $pos, $body]) {
            if (! $started) {
                $started = $pos === $sheetOffset;

                continue;
            }

            // Sub-stream bersarang (mis. grafik di dalam sheet) dilewati
            if ($type === 0x0809) {
                $depth++;

                continue;
            }
            if ($type === 0x000A) {
                if ($depth-- === 0) {
                    break;
                }

                continue;
            }
            if ($depth > 0) {
                continue;
            }

            switch ($type) {
                case 0x00FD: // LABELSST
                    $r = unpack('vrow/vcol/vxf/Vidx', $body);
                    $cells[$r['row']][$r['col']] = $this->sst[$r['idx']] ?? '';
                    break;
                case 0x0204: // LABEL
                    $r = unpack('vrow/vcol', $body);
                    $cells[$r['row']][$r['col']] = $this->unicodeString($body, 6, 2)[0];
                    break;
                case 0x0203: // NUMBER
                    $r = unpack('vrow/vcol/vxf/enum', $body);
                    $cells[$r['row']][$r['col']] = self::numeric($r['num']);
                    break;
                case 0x027E: // RK
                    $r = unpack('vrow/vcol/vxf/Vrk', $body);
                    $cells[$r['row']][$r['col']] = self::numeric(self::rk($r['rk']));
                    break;
                case 0x00BD: // MULRK
                    $r = unpack('vrow/vcol', $body);
                    $n = intdiv(strlen($body) - 6, 6);
                    for ($k = 0; $k < $n; $k++) {
                        $rk = unpack('V', substr($body, 4 + $k * 6 + 2, 4))[1];
                        $cells[$r['row']][$r['col'] + $k] = self::numeric(self::rk($rk));
                    }
                    break;
                case 0x0205: // BOOLERR
                    $r = unpack('vrow/vcol/vxf/Cval/Cerr', $body);
                    $cells[$r['row']][$r['col']] = $r['err'] ? '' : ($r['val'] ? 'TRUE' : 'FALSE');
                    break;
                case 0x0006: // FORMULA (nilai hasil tersimpan)
                    $r = unpack('vrow/vcol', $body);
                    $result = substr($body, 6, 8);
                    if (substr($result, 6, 2) === "\xFF\xFF") {
                        $kind = ord($result[0]);
                        if ($kind === 0) {
                            $pendingFormula = [$r['row'], $r['col']];
                        } elseif ($kind === 1) {
                            $cells[$r['row']][$r['col']] = ord($result[2]) ? 'TRUE' : 'FALSE';
                        } else {
                            $cells[$r['row']][$r['col']] = '';
                        }
                    } else {
                        $cells[$r['row']][$r['col']] = self::numeric(unpack('e', $result)[1]);
                    }
                    break;
                case 0x0207: // STRING (hasil rumus teks)
                    if ($pendingFormula) {
                        $cells[$pendingFormula[0]][$pendingFormula[1]] = $this->unicodeString($body, 0, 2)[0];
                        $pendingFormula = null;
                    }
                    break;
            }
        }

        if (! $cells) {
            return [];
        }

        $rows = [];
        $maxRow = max(array_keys($cells));
        for ($row = 0; $row <= $maxRow; $row++) {
            $line = [];
            $values = $cells[$row] ?? [];
            $maxCol = $values ? max(array_keys($values)) : -1;
            for ($col = 0; $col <= $maxCol; $col++) {
                $line[] = (string) ($values[$col] ?? '');
            }
            $rows[] = $line;
        }

        return $rows;
    }

    /**
     * Shared String Table. String bisa terpotong di batas record CONTINUE; tiap CONTINUE yang memotong
     * karakter diawali byte flag baru (8-bit / 16-bit).
     *
     * @param  list<string>  $parts
     */
    private function readSst(array $parts): void
    {
        $part = 0;
        $pos = 8; // lewati total & jumlah unik
        $unique = unpack('V', substr($parts[0], 4, 4))[1];

        $next = function () use (&$part, &$pos, $parts): bool {
            if ($pos >= strlen($parts[$part])) {
                if (! isset($parts[$part + 1])) {
                    return false;
                }
                $part++;
                $pos = 0;
            }

            return true;
        };
        $bytes = function (int $n) use (&$part, &$pos, $parts, $next): string {
            $out = '';
            while ($n > 0 && $next()) {
                $take = min($n, strlen($parts[$part]) - $pos);
                $out .= substr($parts[$part], $pos, $take);
                $pos += $take;
                $n -= $take;
            }

            return $out;
        };

        for ($s = 0; $s < $unique; $s++) {
            if (! $next()) {
                break;
            }
            $cch = unpack('v', $bytes(2))[1];
            $flags = ord($bytes(1));
            $runs = ($flags & 0x08) ? unpack('v', $bytes(2))[1] : 0;
            $ext = ($flags & 0x04) ? unpack('V', $bytes(4))[1] : 0;
            $wide = (bool) ($flags & 0x01);

            $text = '';
            $remaining = $cch;
            while ($remaining > 0) {
                if ($pos >= strlen($parts[$part])) {
                    if (! isset($parts[$part + 1])) {
                        break;
                    }
                    // Karakter berlanjut di CONTINUE: diawali byte flag encoding baru
                    $part++;
                    $wide = (bool) (ord($parts[$part][0] ?? "\0") & 0x01);
                    $pos = 1;

                    continue;
                }
                $available = strlen($parts[$part]) - $pos;
                $chars = min($remaining, $wide ? intdiv($available, 2) : $available);
                if ($chars <= 0) {
                    $pos = strlen($parts[$part]);

                    continue;
                }
                $chunk = substr($parts[$part], $pos, $chars * ($wide ? 2 : 1));
                $pos += strlen($chunk);
                $text .= mb_convert_encoding($chunk, 'UTF-8', $wide ? 'UTF-16LE' : 'ISO-8859-1');
                $remaining -= $chars;
            }

            $bytes($runs * 4 + $ext);
            $this->sst[] = $text;
        }
    }

    /**
     * XLUnicodeString: panjang ($lenBytes = 1 atau 2 byte), flag, karakter.
     *
     * @return array{0: string, 1: int}
     */
    private function unicodeString(string $body, int $offset, int $lenBytes): array
    {
        $cch = $lenBytes === 2 ? unpack('v', substr($body, $offset, 2))[1] : ord($body[$offset]);
        $flags = ord($body[$offset + $lenBytes] ?? "\0");
        $start = $offset + $lenBytes + 1;
        $wide = (bool) ($flags & 0x01);
        $raw = substr($body, $start, $cch * ($wide ? 2 : 1));

        return [
            $wide ? mb_convert_encoding($raw, 'UTF-8', 'UTF-16LE') : mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1'),
            $start + strlen($raw),
        ];
    }

    private static function rk(int $rk): float
    {
        $div100 = $rk & 0x01;
        if ($rk & 0x02) {
            $value = (float) ($rk >> 2);
            if ($rk & 0x80000000) {
                $value = (float) (($rk >> 2) - (1 << 30));
            }
        } else {
            $value = unpack('e', pack('V', 0).pack('V', $rk & 0xFFFFFFFC))[1];
        }

        return $div100 ? $value / 100 : $value;
    }

    /**
     * Angka sel → teks yang dibaca benar oleh SpreadsheetReader::number() (tanpa pemisah ribuan;
     * desimal 3 digit diberi nol tambahan agar tidak dikira ribuan, mis. 1.125 → "1.1250").
     */
    public static function numeric(float $value): string
    {
        if (is_finite($value) && floor($value) === $value && abs($value) < 1e15) {
            return sprintf('%.0f', $value);
        }

        $text = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        if (preg_match('/\.\d{3}$/', $text)) {
            $text .= '0';
        }

        return $text;
    }

    private function u16(int $offset): int
    {
        return unpack('v', substr($this->data, $offset, 2))[1];
    }

    private function u32(int $offset): int
    {
        return unpack('V', substr($this->data, $offset, 4))[1];
    }

    private function s32(int $offset): int
    {
        return unpack('l', substr($this->data, $offset, 4))[1];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
