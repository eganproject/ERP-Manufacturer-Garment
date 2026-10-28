<?php

namespace App\Support;

use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Penulis Excel (.xlsx) sederhana untuk template impor & ekspor daftar: beberapa sheet, baris judul tebal
 * (beku saat digulir), lebar kolom otomatis. Angka ditulis sebagai angka, lainnya sebagai teks.
 * Kolom "teks" (mis. kode / telepon) diformat Teks agar nol di depan tidak hilang saat diketik di Excel.
 */
class XlsxWriter
{
    private const STYLE_TEXT = 0;

    private const STYLE_HEADER = 1;

    private const STYLE_REQUIRED = 2;

    private const STYLE_NUMBER = 3;

    private const STYLE_FORCE_TEXT = 4;

    private const STYLE_NOTE = 5;

    /**
     * @var list<array{name: string, rows: list<list<mixed>>, header: bool, required: list<int>, text: list<int>}>
     */
    private array $sheets = [];

    /**
     * @param  list<list<mixed>>  $rows  baris pertama = judul kolom bila $header true
     * @param  list<int>  $required  indeks kolom (0-based) yang wajib → judul diberi warna
     * @param  list<int>  $textColumns  indeks kolom yang diformat Teks
     */
    public function sheet(string $name, array $rows, bool $header = true, array $required = [], array $textColumns = []): self
    {
        $this->sheets[] = [
            'name' => mb_substr(trim(str_replace(['/', '\\', '?', '*', '[', ']', ':'], ' ', $name)) ?: 'Sheet', 0, 31),
            'rows' => $rows,
            'header' => $header,
            'required' => $required,
            'text' => $textColumns,
        ];

        return $this;
    }

    public function download(string $filename): BinaryFileResponse
    {
        $path = $this->save();

        return response()->download($path, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    /** Tulis ke file sementara dan kembalikan path-nya. */
    public function save(?string $path = null): string
    {
        $path ??= tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Tidak dapat membuat file Excel.');
        }

        $sheets = $this->sheets ?: [['name' => 'Sheet1', 'rows' => [], 'header' => false, 'required' => [], 'text' => []]];
        $overrides = $workbookSheets = $rels = '';

        foreach ($sheets as $i => $sheet) {
            $n = $i + 1;
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $workbookSheets .= '<sheet name="'.self::esc($sheet['name']).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
            $rels .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
            $zip->addFromString("xl/worksheets/sheet{$n}.xml", self::sheetXml($sheet));
        }

        $stylesId = count($sheets) + 1;

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .$overrides
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$workbookSheets.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$rels
            .'<Relationship Id="rId'.$stylesId.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->close();

        return $path;
    }

    /** @param array{name: string, rows: list<list<mixed>>, header: bool, required: list<int>, text: list<int>} $sheet */
    private static function sheetXml(array $sheet): string
    {
        $rows = '';
        $widths = [];

        foreach (array_values($sheet['rows']) as $r => $values) {
            $isHeader = $sheet['header'] && $r === 0;
            $cells = '';
            foreach (array_values($values) as $c => $value) {
                $text = is_bool($value) ? ($value ? 'Ya' : 'Tidak') : (string) ($value ?? '');
                $widths[$c] = max($widths[$c] ?? 8, min(60, mb_strlen($text) + 2));

                if ($value === null || $value === '') {
                    continue;
                }

                $ref = self::ref($c + 1, $r + 1);
                if ($isHeader) {
                    $style = in_array($c, $sheet['required'], true) ? self::STYLE_REQUIRED : self::STYLE_HEADER;
                    $cells .= self::inline($ref, $text, $style);
                } elseif ((is_int($value) || is_float($value)) && ! in_array($c, $sheet['text'], true)) {
                    $cells .= '<c r="'.$ref.'" s="'.self::STYLE_NUMBER.'"><v>'.self::num((float) $value).'</v></c>';
                } else {
                    $style = in_array($c, $sheet['text'], true) ? self::STYLE_FORCE_TEXT : (! $sheet['header'] ? self::STYLE_NOTE : self::STYLE_TEXT);
                    $cells .= self::inline($ref, $text, $style);
                }
            }
            $rows .= '<row r="'.($r + 1).'">'.$cells.'</row>';
        }

        $cols = '';
        $count = max(count($widths), $sheet['text'] ? max($sheet['text']) + 1 : 0);
        for ($c = 0; $c < $count; $c++) {
            $style = in_array($c, $sheet['text'], true) ? ' style="'.self::STYLE_FORCE_TEXT.'"' : '';
            $cols .= '<col min="'.($c + 1).'" max="'.($c + 1).'" width="'.max(10, $widths[$c] ?? 10).'" customWidth="1"'.$style.'/>';
        }

        $pane = $sheet['header'] && $sheet['rows']
            ? '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            : '';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .$pane
            .($cols ? '<cols>'.$cols.'</cols>' : '')
            .'<sheetData>'.$rows.'</sheetData>'
            .'</worksheet>';
    }

    private static function inline(string $ref, string $text, int $style): string
    {
        return '<c r="'.$ref.'" t="inlineStr" s="'.$style.'"><is><t xml:space="preserve">'.self::esc($text).'</t></is></c>';
    }

    private static function num(float $value): string
    {
        return floor($value) === $value && abs($value) < 1e15 ? sprintf('%.0f', $value) : rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
    }

    private static function ref(int $col, int $row): string
    {
        $letters = '';
        while ($col > 0) {
            $mod = ($col - 1) % 26;
            $letters = chr(65 + $mod).$letters;
            $col = intdiv($col - 1, 26);
        }

        return $letters.$row;
    }

    private static function esc(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '';

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function styles(): string
    {
        // cellXfs mengikuti urutan konstanta STYLE_*
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.####"/></numFmts>'
            .'<fonts count="3">'
            .'<font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
            .'<font><sz val="11"/><color rgb="FF52525B"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="4">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE4E4E7"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFFDE68A"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="2">'
            .'<border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border><left/><right/><top style="thin"/><bottom style="thin"/><diagonal/></border>'
            .'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="6">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            .'<xf numFmtId="0" fontId="1" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}
