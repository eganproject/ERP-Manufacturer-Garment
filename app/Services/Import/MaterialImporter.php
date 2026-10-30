<?php

namespace App\Services\Import;

use App\Models\Material;
use App\Models\Supplier;
use App\Support\UnitConversion;

class MaterialImporter extends Importer
{
    use Concerns;

    public function key(): string
    {
        return 'materials';
    }

    public function label(): string
    {
        return 'Bahan Baku';
    }

    public function description(): string
    {
        return 'Kain, benang, kancing, aksesoris & kemasan beserta satuan, harga beli dan stok minimum.';
    }

    public function ability(): string
    {
        return 'materials.manage';
    }

    public function indexRoute(): ?string
    {
        return 'materials.index';
    }

    public function columns(): array
    {
        return [
            'code' => ['Kode', ['required' => true, 'max' => 30, 'example' => 'KN-CTN30-HTM', 'tip' => 'Kode unik bahan. Kode yang sudah ada = datanya diperbarui.']],
            'name' => ['Nama', ['required' => true, 'example' => 'Kain Cotton Combed 30s Hitam']],
            'category' => ['Kategori', ['max' => 100, 'example' => 'Kain', 'tip' => 'Nama kategori bahan; dibuat otomatis bila belum ada.']],
            'unit' => ['Satuan', ['required' => true, 'max' => 100, 'example' => 'KG', 'tip' => 'Kode atau nama satuan (KG, Meter, Pcs, Roll…); dibuat otomatis bila belum ada.']],
            'purchase_unit' => ['Satuan Beli', ['max' => 100, 'example' => '', 'tip' => 'Opsional. Satuan saat dibeli bila beda dari satuan stok, mis. YD untuk kain yang distok per M; dibuat otomatis bila belum ada.']],
            'purchase_conversion' => ['Isi 1 Satuan Beli', ['type' => 'number', 'min' => 0, 'example' => '', 'tip' => 'Jumlah satuan stok dalam 1 satuan beli, mis. 0,9144 (1 YD = 0,9144 M). Kosong = konversi baku (yard/meter, kg/gram, lusin/kodi).', 'aliases' => ['Konversi']]],
            'color' => ['Warna', ['max' => 50, 'example' => 'Hitam']],
            'price' => ['Harga Beli', ['type' => 'number', 'min' => 0, 'example' => 125000, 'tip' => 'Harga beli standar per satuan, dipakai sebagai harga pokok awal & saran harga PO.', 'aliases' => ['Harga']]],
            'min_stock' => ['Stok Minimum', ['type' => 'number', 'min' => 0, 'example' => 50, 'tip' => 'Batas peringatan stok menipis.']],
            'supplier' => ['Kode Supplier Utama', ['max' => 30, 'example' => 'SUP-001', 'tip' => 'Kode supplier yang biasa memasok (harus sudah terdaftar). Dipakai MRP untuk saran PO.', 'aliases' => ['Supplier']]],
            'notes' => ['Catatan', ['max' => 1000, 'example' => '']],
            'is_active' => ['Aktif', ['type' => 'bool', 'example' => true, 'tip' => 'Kosong = Ya.']],
        ];
    }

    protected function check(array $row, array &$context): array
    {
        if ($row['purchase_unit'] !== null && $row['purchase_conversion'] === null
            && UnitConversion::factor($row['purchase_unit'], $row['unit']) === null) {
            return ["isi kolom Isi 1 Satuan Beli: konversi {$row['purchase_unit']} ke {$row['unit']} tidak baku"];
        }

        if ($row['supplier'] !== null && ! Supplier::where('code', $row['supplier'])->exists()) {
            return ["supplier dengan kode \"{$row['supplier']}\" belum terdaftar"];
        }

        return [];
    }

    protected function save(array $row): string
    {
        $material = Material::where('code', $row['code'])->first() ?? new Material(['code' => $row['code']]);

        return $this->upsert($material, [
            'name' => $row['name'],
            'category_id' => $this->categoryId($row['category'], 'material'),
            'unit_id' => $unitId = $this->unitId($row['unit']),
            ...$this->purchaseUnit($row, $unitId),
            'default_supplier_id' => $row['supplier'] !== null ? Supplier::where('code', $row['supplier'])->value('id') : null,
            'color' => $row['color'],
            'price' => $row['price'],
            'min_stock' => $row['min_stock'],
            'notes' => $row['notes'],
            'is_active' => $row['is_active'],
        ], ['price' => 0, 'min_stock' => 0, 'is_active' => true]);
    }

    /** Satuan beli dari baris impor; isi sama dengan satuan stok = tanpa konversi. */
    private function purchaseUnit(array $row, ?int $unitId): array
    {
        if ($row['purchase_unit'] === null) {
            return []; // sel kosong = nilai lama
        }

        $purchaseUnitId = $this->unitId($row['purchase_unit']);
        if ($purchaseUnitId === $unitId) {
            // Satuan beli = satuan stok: konversi dinonaktifkan
            return ['purchase_unit_id' => $unitId, 'purchase_conversion' => 1];
        }

        return [
            'purchase_unit_id' => $purchaseUnitId,
            'purchase_conversion' => $row['purchase_conversion'] ?: UnitConversion::factor($row['purchase_unit'], $row['unit']) ?? 1,
        ];
    }

    public function exportRows(): iterable
    {
        foreach (Material::with(['category', 'unit', 'purchaseUnit', 'defaultSupplier'])->orderBy('code')->cursor() as $m) {
            yield [$m->code, $m->name, $m->category?->name, $m->unit?->code, $m->hasPurchaseUnit() ? $m->purchaseUnit?->code : null, $m->hasPurchaseUnit() ? (float) $m->purchase_conversion : null, $m->color, (float) $m->price, (float) $m->min_stock, $m->defaultSupplier?->code, $m->notes, self::yesNo($m->is_active)];
        }
    }
}
