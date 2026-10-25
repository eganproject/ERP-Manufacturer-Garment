<?php

use Illuminate\Support\Carbon;

if (! function_exists('rupiah')) {
    function rupiah(float|int|string|null $value, bool $withSymbol = true): string
    {
        $formatted = number_format((float) $value, 0, ',', '.');

        return $withSymbol ? 'Rp '.$formatted : $formatted;
    }
}

if (! function_exists('qty')) {
    function qty(float|int|string|null $value, int $decimals = 2): string
    {
        $value = (float) $value;

        // Tampilkan tanpa desimal jika bilangan bulat
        if (floor($value) == $value) {
            $decimals = 0;
        }

        return number_format($value, $decimals, ',', '.');
    }
}

if (! function_exists('tanggal')) {
    function tanggal(DateTimeInterface|string|null $date, string $format = 'd M Y'): string
    {
        if (! $date) {
            return '-';
        }

        return Carbon::parse($date)->translatedFormat($format);
    }
}
