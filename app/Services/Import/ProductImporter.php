<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Models\ProductVariant;

/**
 * Produk + varian: satu baris = satu varian (SKU). Beberapa baris dengan Kode Produk sama = satu produk.
 */
class ProductImporter extends Importer
{
    use Concerns;

    public function key(): string
    {
        return 'products';
    }

    public function label(): string
    {
        return 'Produk & Varian';
    }

    public function description(): string
    {
        return 'Artikel barang jadi beserta varian ukuran/warna (SKU), harga jual & tarif borongan.';
    }

    public function ability(): string
    {
        return 'products.manage';
    }

    public function indexRoute(): ?string
    {
        return 'products.index';
    }

    protected function uniqueKey(): ?string
    {
        return 'sku';
    }

    public function columns(): array
    {
        return [
            'product_code' => ['Kode Produk', ['required' => true, 'max' => 30, 'example' => 'TS-BSC', 'tip' => 'Kode artikel. Ulangi kode yang sama untuk setiap varian (ukuran/warna) produk tersebut.']],
            'product_name' => ['Nama Produk', ['example' => 'Kaos Basic Cotton 30s', 'tip' => 'Wajib untuk produk baru; cukup diisi di baris pertama produk, baris varian berikutnya boleh kosong.']],
            'category' => ['Kategori', ['max' => 100, 'example' => 'Kaos', 'tip' => 'Nama kategori produk; dibuat otomatis bila belum ada.']],
            'unit' => ['Satuan', ['max' => 100, 'example' => 'PCS', 'tip' => 'Kode / nama satuan. Wajib untuk produk baru; dibuat otomatis bila belum ada.']],
            'price' => ['Harga Jual', ['type' => 'number', 'min' => 0, 'example' => 99000, 'tip' => 'Harga jual standar produk.']],
            'full_garment_rate' => ['Tarif Borongan per Pcs', ['type' => 'number', 'min' => 0, 'example' => 6000, 'tip' => 'Upah borongan 1 pcs jadi (dikerjakan satu orang dari awal sampai jadi). Kosong / 0 = tidak dipakai.', 'aliases' => ['Tarif Borongan']]],
            'description' => ['Keterangan', ['max' => 2000, 'example' => '']],
            'sku' => ['SKU', ['required' => true, 'max' => 50, 'example' => 'TS-BSC-HTM-M', 'tip' => 'Kode unik varian. Samakan dengan SKU di Shopee/TikTok agar pesanan marketplace otomatis terhubung.']],
            'size' => ['Ukuran', ['max' => 20, 'example' => 'M', 'tip' => 'Wajib untuk varian baru (XS, S, M, L, XL, XXL, All Size…).']],
            'color' => ['Warna', ['max' => 50, 'example' => 'Hitam']],
            'variant_price' => ['Harga Varian', ['type' => 'number', 'min' => 0, 'example' => '', 'tip' => 'Isi bila harga varian ini berbeda dari Harga Jual produk (mis. ukuran XXL lebih mahal).']],
            'is_active' => ['Aktif', ['type' => 'bool', 'example' => true, 'tip' => 'Status produk. Kosong = Ya.']],
        ];
    }

    protected function check(array $row, array &$context): array
    {
        $errors = [];
        $code = mb_strtolower($row['product_code']);

        $product = $context['products'][$code] ??= Product::where('code', $row['product_code'])->first() ?? false;
        foreach (['product_name' => 'Nama Produk', 'unit' => 'Satuan'] as $field => $header) {
            if (! $product && $row[$field] === null && ! ($context[$field][$code] ?? false)) {
                $errors[] = "kolom \"{$header}\" wajib diisi untuk produk baru";
            }
            if ($row[$field] !== null) {
                $context[$field][$code] = true;
            }
        }

        $variant = ProductVariant::with('product')->where('sku', $row['sku'])->first();
        if ($variant && mb_strtolower($variant->product->code) !== $code) {
            $errors[] = "SKU \"{$row['sku']}\" sudah dipakai produk {$variant->product->code}";
        }
        if (! $variant && $row['size'] === null) {
            $errors[] = 'kolom "Ukuran" wajib diisi untuk varian baru';
        }

        return $errors;
    }

    protected function save(array $row): string
    {
        $product = Product::where('code', $row['product_code'])->first() ?? new Product(['code' => $row['product_code']]);
        $productIsNew = ! $product->exists;

        $this->upsert($product, [
            'name' => $row['product_name'],
            'category_id' => $this->categoryId($row['category'], 'product'),
            'unit_id' => $this->unitId($row['unit']),
            'price' => $row['price'],
            'full_garment_rate' => $row['full_garment_rate'],
            'description' => $row['description'],
            'is_active' => $row['is_active'],
        ], ['price' => 0, 'full_garment_rate' => 0, 'is_active' => true]);

        $variant = ProductVariant::where('sku', $row['sku'])->first() ?? new ProductVariant(['sku' => $row['sku'], 'product_id' => $product->id]);
        $result = $this->upsert($variant, [
            'size' => $row['size'],
            'color' => $row['color'],
            'price' => $row['variant_price'],
        ]);

        return $productIsNew || $result === 'created' ? 'created' : 'updated';
    }

    public function exportRows(): iterable
    {
        foreach (Product::with(['category', 'unit', 'variants'])->orderBy('code')->get() as $p) {
            foreach ($p->variants as $v) {
                yield [$p->code, $p->name, $p->category?->name, $p->unit?->code, (float) $p->price, (float) $p->full_garment_rate, $p->description,
                    $v->sku, $v->size, $v->color, self::num($v->price), self::yesNo($p->is_active)];
            }
        }
    }

    public function notes(): array
    {
        return ['Produk dengan 3 ukuran = 3 baris dengan Kode Produk sama dan SKU berbeda. Data produk (nama, harga, satuan) diambil dari baris tersebut; varian lama yang tidak ada di file tidak dihapus.'];
    }
}
