<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Model;

class DocumentNumber
{
    /**
     * Buat nomor dokumen berurutan per bulan, mis. PO-202610-0001.
     *
     * @param  class-string<Model>  $model
     */
    public static function generate(string $model, string $prefix, ?\DateTimeInterface $date = null, string $column = 'number'): string
    {
        $date ??= now();
        $base = sprintf('%s-%s-', $prefix, $date->format('Ym'));

        $last = $model::query()
            ->where($column, 'like', $base.'%')
            ->lockForUpdate()
            ->orderByDesc($column)
            ->value($column);

        $next = $last ? ((int) substr($last, strlen($base))) + 1 : 1;

        return $base.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
