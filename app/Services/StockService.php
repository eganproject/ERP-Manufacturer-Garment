<?php

namespace App\Services;

use App\Enums\StockLocation;
use App\Enums\StockMovementType;
use App\Models\Material;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class StockService
{
    /**
     * Catat mutasi stok dan perbarui saldo. Quantity positif = masuk, negatif = keluar.
     * Harus dipanggil di dalam DB::transaction.
     *
     * $unitCost pada barang masuk memperbarui harga pokok rata-rata tertimbang (moving average).
     * Barang keluar dicatat dengan harga pokok rata-rata saat itu.
     *
     * $location Defect = stok barang cacat (karantina) varian produk, dengan saldo & harga pokok
     * rata-rata sendiri (defect_stock / defect_cost); tidak bisa dikirim ke pelanggan.
     *
     * $warehouse = gudang tempat mutasi (kosong = gudang default). Saldo per gudang ada di warehouse_stocks
     * dan dicek tidak boleh minus per gudang; saldo total & harga pokok rata-rata tetap per barang.
     */
    public function move(
        Material|ProductVariant $item,
        float $quantity,
        StockMovementType $type,
        ?Model $reference = null,
        ?string $referenceNumber = null,
        ?string $notes = null,
        ?\DateTimeInterface $date = null,
        bool $allowNegative = false,
        ?float $unitCost = null,
        StockLocation $location = StockLocation::Main,
        Warehouse|int|null $warehouse = null,
    ): StockMovement {
        $defect = $location === StockLocation::Defect;

        if ($defect && ! $item instanceof ProductVariant) {
            throw new \LogicException('Stok cacat hanya untuk barang jadi.');
        }

        [$stockColumn, $costColumn] = $defect ? ['defect_stock', 'defect_cost'] : ['stock', 'average_cost'];

        /** @var Material|ProductVariant $locked */
        $locked = $item->newQuery()->lockForUpdate()->findOrFail($item->getKey());
        $newBalance = round((float) $locked->{$stockColumn} + $quantity, 2);

        $warehouse = $warehouse instanceof Warehouse ? $warehouse : ($warehouse ? Warehouse::findOrFail($warehouse) : Warehouse::default());
        $whColumn = $defect ? 'defect_quantity' : 'quantity';
        $whStock = WarehouseStock::firstOrCreate([
            'warehouse_id' => $warehouse->id,
            'stockable_type' => $locked->getMorphClass(),
            'stockable_id' => $locked->getKey(),
        ]);
        $whStock = WarehouseStock::lockForUpdate()->find($whStock->id);
        $whBalance = round((float) $whStock->{$whColumn} + $quantity, 2);

        if (($newBalance < 0 || $whBalance < 0) && ! $allowNegative) {
            $name = $locked instanceof Material ? $locked->name : $locked->display_name;
            $multi = Warehouse::count() > 1;
            $available = $multi ? (float) $whStock->{$whColumn} : (float) $locked->{$stockColumn};

            throw ValidationException::withMessages([
                'stock' => ($defect ? 'Stok cacat ' : 'Stok ')."{$name}".($multi ? " di {$warehouse->name}" : '').' tidak mencukupi (tersedia '.qty($available).', dibutuhkan '.qty(abs($quantity)).').',
            ]);
        }

        $whStock->update([$whColumn => $whBalance]);

        $currentCost = $locked instanceof Material ? $locked->costPrice() : (float) $locked->{$costColumn};
        $attributes = [$stockColumn => $newBalance];

        if ($quantity > 0 && $unitCost !== null) {
            $previousQty = max(0, (float) $locked->{$stockColumn});
            $averageCost = $previousQty > 0 && $currentCost > 0
                ? ($previousQty * $currentCost + $quantity * $unitCost) / ($previousQty + $quantity)
                : $unitCost;
            $attributes[$costColumn] = round($averageCost, $locked instanceof Material ? 4 : 2);
        }

        $locked->forceFill($attributes)->save();

        foreach ($attributes as $key => $value) {
            $item->setAttribute($key, $value);
        }

        return $locked->stockMovements()->create([
            'date' => ($date ?? now())->format('Y-m-d'),
            'warehouse_id' => $warehouse->id,
            'warehouse_balance' => $whBalance,
            'type' => $type,
            'location' => $location,
            'quantity' => $quantity,
            'balance' => $newBalance,
            'unit_cost' => $quantity > 0 && $unitCost !== null ? $unitCost : $currentCost,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'reference_number' => $referenceNumber,
            'notes' => $notes,
            'user_id' => auth()->id(),
        ]);
    }
}
