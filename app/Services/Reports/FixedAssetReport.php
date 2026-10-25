<?php

namespace App\Services\Reports;

use App\Enums\FixedAssetStatus;
use App\Models\AssetDepreciation;
use App\Models\FixedAsset;
use App\Support\ReportTable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Daftar aset tetap per tanggal: harga perolehan, akumulasi penyusutan, nilai buku, dan penyusutan dalam periode.
 */
class FixedAssetReport
{
    /**
     * @return array{rows: Collection, categories: Collection, totals: array<string, float|int>}
     */
    public function build(Carbon $from, Carbon $to, ?int $categoryId = null, ?string $status = null, ?string $search = null): array
    {
        $assets = FixedAsset::with('category')
            ->where('status', '!=', FixedAssetStatus::Cancelled)
            ->whereDate('acquisition_date', '<=', $to->toDateString())
            ->where(fn ($q) => $q->whereNull('disposal_date')->orWhereDate('disposal_date', '>=', $from->toDateString()))
            ->when($categoryId, fn ($q, $id) => $q->where('asset_category_id', $id))
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->when($search, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%")
                ->orWhere('serial_number', 'like', "%{$s}%")->orWhere('location', 'like', "%{$s}%")))
            ->orderBy('asset_category_id')->orderBy('acquisition_date')->orderBy('id')
            ->get();

        $toMonth = $to->copy()->startOfMonth()->toDateString();
        $fromMonth = $from->copy()->startOfMonth()->toDateString();
        $ids = $assets->pluck('id');

        $accumulated = AssetDepreciation::whereIn('fixed_asset_id', $ids)->whereDate('period', '<=', $toMonth)
            ->groupBy('fixed_asset_id')->selectRaw('fixed_asset_id, SUM(amount) AS total')->pluck('total', 'fixed_asset_id');
        $inPeriod = AssetDepreciation::whereIn('fixed_asset_id', $ids)->whereDate('period', '>=', $fromMonth)->whereDate('period', '<=', $toMonth)
            ->groupBy('fixed_asset_id')->selectRaw('fixed_asset_id, SUM(amount) AS total')->pluck('total', 'fixed_asset_id');

        $rows = $assets->map(function (FixedAsset $asset) use ($accumulated, $inPeriod, $to) {
            $disposed = $asset->disposal_date && $asset->disposal_date->lte($to);
            $acc = round((float) $asset->opening_accumulated + (float) ($accumulated[$asset->id] ?? 0), 2);

            return [
                'key' => $asset->id,
                'asset' => $asset,
                'number' => $asset->number,
                'name' => $asset->name,
                'category' => $asset->category->name,
                'location' => $asset->location,
                'acquisition_date' => $asset->acquisition_date,
                'method' => $asset->useful_life_months > 0 ? $asset->depreciation_method->label() : '-',
                'life' => $asset->lifeLabel(),
                'cost' => $disposed ? 0.0 : (float) $asset->cost,
                'depreciation' => round((float) ($inPeriod[$asset->id] ?? 0), 2),
                'accumulated' => $disposed ? 0.0 : $acc,
                'book_value' => $disposed ? 0.0 : round((float) $asset->cost - $acc, 2),
                'status' => $disposed ? 'Dilepas '.tanggal($asset->disposal_date) : 'Aktif',
                'disposed' => $disposed,
            ];
        });

        $categories = $rows->groupBy('category')->map(fn (Collection $items, string $name) => [
            'name' => $name,
            'items' => $items->where('disposed', false)->count(),
            'cost' => round($items->sum('cost'), 2),
            'accumulated' => round($items->sum('accumulated'), 2),
            'book_value' => round($items->sum('book_value'), 2),
            'depreciation' => round($items->sum('depreciation'), 2),
        ])->values();

        return [
            'rows' => $rows,
            'categories' => $categories,
            'totals' => [
                'items' => $rows->where('disposed', false)->count(),
                'cost' => round($rows->sum('cost'), 2),
                'accumulated' => round($rows->sum('accumulated'), 2),
                'book_value' => round($rows->sum('book_value'), 2),
                'depreciation' => round($rows->sum('depreciation'), 2),
            ],
        ];
    }

    public function table(array $data, Carbon $from, Carbon $to, ?string $categoryName = null): ReportTable
    {
        return new ReportTable(
            'Daftar Aset Tetap',
            'Per '.tanggal($to).' · penyusutan '.tanggal($from).' – '.tanggal($to).($categoryName ? ' · '.$categoryName : ''),
            [
                'number' => ['No. Aset', 'text'],
                'name' => ['Nama Aset', 'text'],
                'category' => ['Kategori', 'text'],
                'location' => ['Lokasi', 'text'],
                'acquisition_date' => ['Tgl Perolehan', 'date'],
                'method' => ['Metode', 'text'],
                'life' => ['Masa Manfaat', 'text'],
                'cost' => ['Harga Perolehan', 'money'],
                'depreciation' => ['Penyusutan Periode', 'money'],
                'accumulated' => ['Akumulasi Penyusutan', 'money'],
                'book_value' => ['Nilai Buku', 'money'],
                'status' => ['Status', 'text'],
            ],
            $data['rows']->map(fn ($r) => collect($r)->except(['asset', 'disposed'])->all()),
            [
                'cost' => $data['totals']['cost'],
                'depreciation' => $data['totals']['depreciation'],
                'accumulated' => $data['totals']['accumulated'],
                'book_value' => $data['totals']['book_value'],
            ],
            [
                ['Jumlah aset aktif', (string) $data['totals']['items']],
                ['Harga perolehan', rupiah($data['totals']['cost'])],
                ['Akumulasi penyusutan', rupiah($data['totals']['accumulated'])],
                ['Nilai buku', rupiah($data['totals']['book_value'])],
            ],
            'landscape',
        );
    }
}
