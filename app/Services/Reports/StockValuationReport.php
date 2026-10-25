<?php

namespace App\Services\Reports;

use App\Models\Material;
use App\Models\ProductVariant;
use App\Support\ReportTable;
use Illuminate\Support\Collection;

/**
 * Nilai persediaan saat ini berdasarkan harga pokok (HPP rata-rata tertimbang).
 *
 * Bahan baku: harga pokok rata-rata, atau harga beli standar bila belum ada penerimaan.
 * Barang jadi: HPP rata-rata hasil produksi, atau HPP standar BOM aktif bila belum pernah diproduksi.
 * Stok cacat (karantina) dinilai dengan HPP rata-rata stok cacat dan ikut dalam nilai persediaan.
 */
class StockValuationReport
{
    public const TYPES = ['materials' => 'Bahan Baku', 'products' => 'Barang Jadi'];

    /**
     * @param  'materials'|'products'  $type
     * @return array{rows: Collection, categories: Collection, totals: array<string, float|int>}
     */
    public function build(string $type, ?int $categoryId = null, bool $includeZero = false): array
    {
        $rows = $type === 'materials'
            ? $this->materials($categoryId, $includeZero)
            : $this->products($categoryId, $includeZero);

        $categories = $rows->groupBy('category')->map(fn (Collection $items, string $name) => [
            'name' => $name,
            'items' => $items->count(),
            'value' => round($items->sum('value'), 2),
        ])->sortByDesc('value')->values();

        $total = $rows->sum('value');
        $categories = $categories->map(fn ($c) => $c + ['share' => $total > 0 ? round($c['value'] / $total * 100, 1) : 0]);

        return [
            'rows' => $rows,
            'categories' => $categories,
            'totals' => [
                'items' => $rows->count(),
                'value' => round($total, 2),
                'selling_value' => round($rows->sum('selling_value'), 2),
                'estimated' => $rows->where('basis', '!=', 'average')->count(),
            ],
        ];
    }

    /** Total nilai persediaan bahan + barang jadi (dipakai di dashboard). */
    public function grandTotal(): array
    {
        return [
            'materials' => $this->build('materials')['totals']['value'],
            'products' => $this->build('products')['totals']['value'],
        ];
    }

    private function materials(?int $categoryId, bool $includeZero): Collection
    {
        return Material::with(['unit', 'category'])
            ->when($categoryId, fn ($q, $id) => $q->where('category_id', $id))
            ->when(! $includeZero, fn ($q) => $q->where('stock', '!=', 0))
            ->orderBy('name')
            ->get()
            ->map(function (Material $m) {
                $unitCost = $m->costPrice();

                return [
                    'key' => $m->id,
                    'code' => $m->code,
                    'name' => $m->name.($m->color ? ' ('.$m->color.')' : ''),
                    'category' => $m->category?->name ?? 'Tanpa Kategori',
                    'unit' => $m->unit?->code,
                    'stock' => (float) $m->stock,
                    'unit_cost' => $unitCost,
                    'basis' => (float) $m->average_cost > 0 ? 'average' : 'price',
                    'value' => round((float) $m->stock * $unitCost, 2),
                    'selling_value' => null,
                    'low_stock' => $m->isLowStock(),
                ];
            });
    }

    private function products(?int $categoryId, bool $includeZero): Collection
    {
        return ProductVariant::with(['product.unit', 'product.category', 'product.activeBom.items.material'])
            ->when($categoryId, fn ($q, $id) => $q->whereHas('product', fn ($p) => $p->where('category_id', $id)))
            ->when(! $includeZero, fn ($q) => $q->where(fn ($w) => $w->where('stock', '!=', 0)->orWhere('defect_stock', '!=', 0)))
            ->get()
            ->sortBy(fn (ProductVariant $v) => $v->product->name.'|'.$v->id)
            ->values()
            ->map(function (ProductVariant $v) {
                [$unitCost, $basis] = match (true) {
                    (float) $v->average_cost > 0 => [(float) $v->average_cost, 'average'],
                    $v->product->activeBom !== null => [$v->product->activeBom->standardUnitCost(), 'standard'],
                    default => [0.0, 'none'],
                };

                return [
                    'key' => $v->id,
                    'code' => $v->sku,
                    'name' => $v->display_name,
                    'category' => $v->product->category?->name ?? 'Tanpa Kategori',
                    'unit' => $v->product->unit?->code,
                    'stock' => (float) $v->stock,
                    'unit_cost' => $unitCost,
                    'basis' => $basis,
                    'defect_stock' => (float) $v->defect_stock,
                    'defect_value' => round((float) $v->defect_stock * (float) $v->defect_cost, 2),
                    'value' => round((float) $v->stock * $unitCost + (float) $v->defect_stock * (float) $v->defect_cost, 2),
                    'selling_value' => round((float) $v->stock * $v->selling_price, 2),
                    'low_stock' => false,
                ];
            });
    }

    public static function basisLabel(string $basis): string
    {
        return match ($basis) {
            'average' => 'Rata-rata',
            'price' => 'Harga beli',
            'standard' => 'Standar BOM',
            default => 'Belum ada',
        };
    }

    public function table(string $type, array $data, ?string $categoryName = null): ReportTable
    {
        $isProducts = $type === 'products';

        $columns = [
            'code' => [$isProducts ? 'SKU' : 'Kode', 'text'],
            'name' => [$isProducts ? 'Produk' : 'Bahan', 'text'],
            'category' => ['Kategori', 'text'],
            'stock' => ['Stok', 'qty'],
            'unit' => ['Satuan', 'text'],
            'unit_cost' => ['HPP / Satuan', 'money'],
            'basis_label' => ['Dasar HPP', 'text'],
            ...($isProducts ? ['defect_stock' => ['Stok Cacat', 'qty'], 'defect_value' => ['Nilai Stok Cacat', 'money']] : []),
            'value' => ['Nilai Persediaan', 'money'],
            ...($isProducts ? ['selling_value' => ['Nilai Jual', 'money']] : []),
        ];

        $rows = $data['rows']->map(fn ($row) => $row + ['basis_label' => self::basisLabel($row['basis'])]);
        $totals = ['value' => $data['totals']['value']] + ($isProducts ? ['selling_value' => $data['totals']['selling_value'], 'defect_value' => round($data['rows']->sum('defect_value'), 2)] : []);

        return new ReportTable(
            'Laporan Nilai Persediaan',
            self::TYPES[$type].' · per '.tanggal(today()).($categoryName ? ' · '.$categoryName : ''),
            $columns,
            $rows,
            $totals,
            [
                ['Jumlah item', (string) $data['totals']['items']],
                ['Nilai persediaan', rupiah($data['totals']['value'])],
                ...($isProducts ? [['Nilai jual', rupiah($data['totals']['selling_value'])]] : []),
            ],
            'landscape',
        );
    }
}
