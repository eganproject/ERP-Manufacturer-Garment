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

if (! function_exists('terbilang')) {
    /**
     * Ubah angka menjadi kalimat Bahasa Indonesia, mis. 1250 => "seribu dua ratus lima puluh".
     */
    function terbilang(float|int|string|null $value): string
    {
        $n = (int) round(abs((float) $value));
        $words = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];

        $convert = function (int $n) use (&$convert, $words): string {
            return match (true) {
                $n < 12 => $words[$n],
                $n < 20 => $convert($n - 10).' belas',
                $n < 100 => $convert(intdiv($n, 10)).' puluh '.$convert($n % 10),
                $n < 200 => 'seratus '.$convert($n - 100),
                $n < 1000 => $convert(intdiv($n, 100)).' ratus '.$convert($n % 100),
                $n < 2000 => 'seribu '.$convert($n - 1000),
                $n < 1_000_000 => $convert(intdiv($n, 1000)).' ribu '.$convert($n % 1000),
                $n < 1_000_000_000 => $convert(intdiv($n, 1_000_000)).' juta '.$convert($n % 1_000_000),
                $n < 1_000_000_000_000 => $convert(intdiv($n, 1_000_000_000)).' miliar '.$convert($n % 1_000_000_000),
                default => $convert(intdiv($n, 1_000_000_000_000)).' triliun '.$convert($n % 1_000_000_000_000),
            };
        };

        $result = trim(preg_replace('/\s+/', ' ', $convert($n)));

        return $result === '' ? 'nol' : $result;
    }
}
