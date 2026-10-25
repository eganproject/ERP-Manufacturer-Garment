<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Tabel laporan yang siap diekspor ke Excel / PDF.
 *
 * Kolom: key => [label, tipe] dengan tipe text | number | money | qty | percent | date.
 */
class ReportTable
{
    /**
     * @param  array<string, array{0: string, 1: string}>  $columns
     * @param  Collection<int, array<string, mixed>>|array<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>|null  $totals
     * @param  list<array{0: string, 1: string}>  $summary  pasangan [label, nilai] yang dicetak di atas tabel
     */
    public function __construct(
        public string $title,
        public string $subtitle,
        public array $columns,
        public Collection|array $rows,
        public ?array $totals = null,
        public array $summary = [],
        public string $orientation = 'portrait',
    ) {
        $this->rows = collect($rows)->values();
    }

    public function filename(): string
    {
        return str($this->title.' '.$this->subtitle)->slug()->limit(80, '')->toString();
    }

    /** Nilai sel dalam bentuk teks untuk PDF. */
    public function format(string $key, mixed $value): string
    {
        if ($value === null || $value === '') {
            return '-';
        }

        return match ($this->columns[$key][1] ?? 'text') {
            'money' => rupiah($value, false),
            'qty', 'number' => qty($value),
            'percent' => number_format((float) $value, 1, ',', '.').'%',
            'date' => tanggal($value),
            default => (string) $value,
        };
    }

    public function isNumeric(string $key): bool
    {
        return in_array($this->columns[$key][1] ?? 'text', ['money', 'qty', 'number', 'percent'], true);
    }
}
