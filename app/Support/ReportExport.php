<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Support\Carbon;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use ZipArchive;

/**
 * Ekspor ReportTable ke Excel (.xlsx) atau PDF.
 *
 * File .xlsx ditulis langsung (SpreadsheetML minimal) dengan ZipArchive sehingga tidak perlu paket tambahan.
 */
class ReportExport
{
    // Indeks gaya pada styles.xml
    private const STYLE_TEXT = 0;

    private const STYLE_HEADER = 1;

    private const STYLE_MONEY = 2;

    private const STYLE_QTY = 3;

    private const STYLE_PERCENT = 4;

    private const STYLE_DATE = 5;

    private const STYLE_TITLE = 6;

    private const STYLE_TOTAL_TEXT = 7;

    private const STYLE_TOTAL_MONEY = 8;

    private const STYLE_TOTAL_QTY = 9;

    private const STYLE_TOTAL_PERCENT = 10;

    public static function respond(ReportTable $table, ?string $format): ?Response
    {
        return match ($format) {
            'xlsx' => self::xlsx($table),
            'pdf' => self::pdf($table),
            default => null,
        };
    }

    public static function pdf(ReportTable $table): Response
    {
        return DocumentPdf::stream('pdf.report', ['table' => $table], $table->filename(), $table->orientation);
    }

    public static function xlsx(ReportTable $table): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Tidak dapat membuat file Excel.');
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="'.self::esc(mb_substr(str_replace(['/', '\\', '?', '*', '[', ']', ':'], ' ', $table->title), 0, 31)).'" sheetId="1" r:id="rId1"/></sheets>'
            .'</workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/styles.xml', self::styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::sheet($table));
        $zip->close();

        return response()->download($path, $table->filename().'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
    }

    private static function sheet(ReportTable $table): string
    {
        $keys = array_keys($table->columns);
        $rows = [];
        $r = 0;

        $rows[] = self::row(++$r, [self::cell(1, $r, $table->title, self::STYLE_TITLE)]);
        $rows[] = self::row(++$r, [self::cell(1, $r, $table->subtitle, self::STYLE_TEXT)]);

        foreach ($table->summary as [$label, $value]) {
            $rows[] = self::row(++$r, [self::cell(1, $r, $label, self::STYLE_TEXT), self::cell(2, $r, $value, self::STYLE_TEXT)]);
        }

        $r++;
        $headerRow = ++$r;
        $rows[] = self::row($headerRow, array_map(
            fn ($key, $i) => self::cell($i + 1, $headerRow, $table->columns[$key][0], self::STYLE_HEADER),
            $keys, array_keys($keys),
        ));

        foreach ($table->rows as $data) {
            $r++;
            $cells = [];
            foreach ($keys as $i => $key) {
                $cells[] = self::typedCell($i + 1, $r, $data[$key] ?? null, $table->columns[$key][1], false);
            }
            $rows[] = self::row($r, $cells);
        }

        if ($table->totals !== null) {
            $r++;
            $cells = [];
            foreach ($keys as $i => $key) {
                $value = $i === 0 ? 'TOTAL' : ($table->totals[$key] ?? null);
                $cells[] = self::typedCell($i + 1, $r, $value, $i === 0 ? 'text' : $table->columns[$key][1], true);
            }
            $rows[] = self::row($r, $cells);
        }

        $cols = '';
        foreach ($keys as $i => $key) {
            $longest = max(mb_strlen($table->columns[$key][0]), ...$table->rows->map(fn ($row) => mb_strlen($table->format($key, $row[$key] ?? null)))->push(0)->all());
            $cols .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.min(60, max(10, $longest + 3)).'" customWidth="1"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"><pane ySplit="'.$headerRow.'" topLeftCell="A'.($headerRow + 1).'" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            .'<cols>'.$cols.'</cols>'
            .'<sheetData>'.implode('', $rows).'</sheetData>'
            .'</worksheet>';
    }

    private static function typedCell(int $col, int $row, mixed $value, string $type, bool $total): string
    {
        if ($value === null || $value === '') {
            return self::cell($col, $row, '', $total ? self::STYLE_TOTAL_TEXT : self::STYLE_TEXT);
        }

        return match ($type) {
            'money' => self::number($col, $row, (float) $value, $total ? self::STYLE_TOTAL_MONEY : self::STYLE_MONEY),
            'qty', 'number' => self::number($col, $row, (float) $value, $total ? self::STYLE_TOTAL_QTY : self::STYLE_QTY),
            'percent' => self::number($col, $row, (float) $value / 100, $total ? self::STYLE_TOTAL_PERCENT : self::STYLE_PERCENT),
            'date' => self::number($col, $row, self::excelDate($value), self::STYLE_DATE),
            default => self::cell($col, $row, (string) $value, $total ? self::STYLE_TOTAL_TEXT : self::STYLE_TEXT),
        };
    }

    private static function row(int $index, array $cells): string
    {
        return '<row r="'.$index.'">'.implode('', $cells).'</row>';
    }

    private static function cell(int $col, int $row, string $value, int $style): string
    {
        return '<c r="'.self::ref($col, $row).'" t="inlineStr" s="'.$style.'"><is><t xml:space="preserve">'.self::esc($value).'</t></is></c>';
    }

    private static function number(int $col, int $row, float $value, int $style): string
    {
        return '<c r="'.self::ref($col, $row).'" s="'.$style.'"><v>'.$value.'</v></c>';
    }

    private static function excelDate(DateTimeInterface|string $value): float
    {
        $date = Carbon::parse($value)->startOfDay();

        return (float) Carbon::create(1899, 12, 30)->diffInDays($date);
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
        // Buang karakter kontrol yang tidak valid di XML
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? '';

        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function styles(): string
    {
        // cellXfs mengikuti urutan konstanta STYLE_*
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="3">'
            .'<numFmt numFmtId="164" formatCode="#,##0"/>'
            .'<numFmt numFmtId="165" formatCode="#,##0.##"/>'
            .'<numFmt numFmtId="166" formatCode="dd/mm/yyyy"/>'
            .'</numFmts>'
            .'<fonts count="3">'
            .'<font><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="14"/><name val="Calibri"/></font>'
            .'</fonts>'
            .'<fills count="4">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFE0E7FF"/><bgColor indexed="64"/></patternFill></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFF3F4F6"/><bgColor indexed="64"/></patternFill></fill>'
            .'</fills>'
            .'<borders count="2">'
            .'<border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border><left/><right/><top style="thin"/><bottom style="thin"/><diagonal/></border>'
            .'</borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="11">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="10" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="0" fontId="1" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            .'<xf numFmtId="164" fontId="1" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>'
            .'<xf numFmtId="165" fontId="1" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>'
            .'<xf numFmtId="10" fontId="1" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyFont="1" applyFill="1" applyBorder="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }
}
