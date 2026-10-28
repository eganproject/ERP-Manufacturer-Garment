<?php

namespace App\Services\Import;

use App\Enums\StockMovementType;
use App\Models\Material;
use App\Models\ProductVariant;
use App\Models\Warehouse;
use App\Services\Accounting\AutoJournal;
use App\Services\StockService;
use Illuminate\Support\Carbon;

/**
 * Stok awal / tambahan stok massal (bahan & barang jadi) beserta harga pokoknya.
 * Tiap baris = mutasi penyesuaian masuk + jurnal (mutasi pertama sebuah barang → Ekuitas Saldo Awal).
 */
class OpeningStockImporter extends Importer
{
    public function __construct(private StockService $stock, private AutoJournal $journal) {}

    public function key(): string
    {
        return 'opening-stock';
    }

    public function label(): string
    {
        return 'Stok Awal';
    }

    public function description(): string
    {
        return 'Saldo awal stok bahan baku & barang jadi per gudang beserta harga pokoknya (nilai persediaan masuk ke neraca).';
    }

    public function ability(): string
    {
        return 'stock.adjust';
    }

    public function indexRoute(): ?string
    {
        return 'stock.products';
    }

    protected function uniqueKey(): ?string
    {
        return null;
    }

    public function columns(): array
    {
        return [
            'type' => ['Jenis', ['type' => 'enum', 'options' => ['Bahan' => 'material', 'Bahan Baku' => 'material', 'Material' => 'material', 'Produk' => 'variant', 'Barang Jadi' => 'variant', 'Varian' => 'variant'], 'choices' => ['Bahan', 'Produk'], 'example' => 'Produk', 'tip' => 'Bahan = kode bahan baku, Produk = SKU varian. Kosong = dicari otomatis dari kodenya.']],
            'code' => ['Kode / SKU', ['required' => true, 'max' => 50, 'example' => 'TS-BSC-HTM-M', 'tip' => 'Kode bahan baku atau SKU varian produk yang sudah terdaftar.', 'aliases' => ['Kode', 'SKU']]],
            'warehouse' => ['Gudang', ['max' => 255, 'example' => '', 'tip' => 'Kode atau nama gudang. Kosong = gudang utama.']],
            'quantity' => ['Jumlah', ['type' => 'number', 'required' => true, 'example' => 120, 'tip' => 'Jumlah yang DITAMBAHKAN ke stok (dalam satuan barang). Untuk menyamakan stok dengan hitungan fisik pakai Stock Opname.', 'aliases' => ['Qty', 'Stok']]],
            'unit_cost' => ['Harga Pokok per Unit', ['type' => 'number', 'min' => 0, 'example' => 42000, 'tip' => 'Nilai per satuan untuk persediaan & HPP. Kosong = harga pokok rata-rata sekarang (bahan: harga beli). Wajib untuk barang jadi yang belum punya harga pokok.', 'aliases' => ['Harga Pokok', 'HPP']]],
            'date' => ['Tanggal', ['type' => 'date', 'example' => '01/10/2026', 'tip' => 'Tanggal saldo awal. Kosong = hari ini. Tidak boleh di periode akuntansi yang sudah ditutup.']],
            'notes' => ['Catatan', ['max' => 200, 'example' => 'Saldo awal']],
        ];
    }

    protected function check(array $row, array &$context): array
    {
        $errors = [];

        if ($row['quantity'] !== null && $row['quantity'] <= 0) {
            $errors[] = 'kolom "Jumlah" harus lebih dari 0';
        }

        $item = $this->item($row);
        if (is_string($item)) {
            $errors[] = $item;
        } elseif ($item instanceof ProductVariant && $row['unit_cost'] === null && (float) $item->average_cost <= 0) {
            $errors[] = "barang jadi {$item->sku} belum punya harga pokok, isi kolom \"Harga Pokok per Unit\"";
        }

        if ($row['warehouse'] !== null && ! $this->warehouse($row['warehouse'])) {
            $errors[] = "gudang \"{$row['warehouse']}\" tidak ditemukan";
        }

        return $errors;
    }

    protected function save(array $row): string
    {
        $item = $this->item($row);
        $warehouse = $row['warehouse'] !== null ? $this->warehouse($row['warehouse']) : null;
        $unitCost = $row['unit_cost'] ?? ($item instanceof Material ? $item->costPrice() : (float) $item->average_cost);

        $movement = $this->stock->move(
            $item, (float) $row['quantity'], StockMovementType::Adjustment, null, null,
            $row['notes'] ?? 'Saldo awal (impor Excel)', Carbon::parse($row['date'] ?? today()),
            unitCost: (float) $unitCost, warehouse: $warehouse,
        );
        $this->journal->stockAdjustment($movement);

        return 'created';
    }

    /** Barang menurut jenis + kode, atau pesan kesalahan. */
    private function item(array $row): Material|ProductVariant|string
    {
        $variant = $row['type'] !== 'material' ? ProductVariant::where('sku', $row['code'])->first() : null;
        $material = $row['type'] !== 'variant' ? Material::where('code', $row['code'])->first() : null;

        if ($variant && $material) {
            return "kode \"{$row['code']}\" dipakai bahan baku dan SKU produk sekaligus, isi kolom \"Jenis\"";
        }

        return $variant ?? $material ?? match ($row['type']) {
            'material' => "bahan baku dengan kode \"{$row['code']}\" belum terdaftar",
            'variant' => "SKU produk \"{$row['code']}\" belum terdaftar",
            default => "kode / SKU \"{$row['code']}\" belum terdaftar di bahan baku maupun produk",
        };
    }

    private function warehouse(string $text): ?Warehouse
    {
        return Warehouse::active()->whereRaw('lower(code) = ?', [mb_strtolower($text)])->first()
            ?? Warehouse::active()->whereRaw('lower(name) = ?', [mb_strtolower($text)])->first();
    }

    public function notes(): array
    {
        return [
            'Stok awal MENAMBAH stok yang ada. Mengimpor file yang sama dua kali = stok tercatat dua kali.',
            'Nilai (jumlah × harga pokok) dijurnal ke Persediaan; untuk barang yang belum pernah punya mutasi lawannya Ekuitas Saldo Awal, selain itu akun Selisih Penyesuaian Stok.',
            'Kode bahan & SKU harus sudah terdaftar: impor Bahan Baku / Produk & Varian lebih dulu.',
        ];
    }
}
