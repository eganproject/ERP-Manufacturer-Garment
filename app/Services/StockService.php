<?php

namespace App\Services;

use App\Enums\StockLocation;
use App\Enums\StockMovementType;
use App\Models\Material;
use App\Models\ProductVariant;
use App\Models\StockMovement;
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
    ): StockMovement {
        $defect = $location === StockLocation::Defect;

        if ($defect && ! $item instanceof ProductVariant) {
            throw new \LogicException('Stok cacat hanya untuk barang jadi.');
        }

        [$stockColumn, $costColumn] = $defect ? ['defect_stock', 'defect_cost'] : ['stock', 'average_cost'];

        /** @var Material|ProductVariant $locked */
        $locked = $item->newQuery()->lockForUpdate()->findOrFail($item->getKey());
        $newBalance = round((float) $locked->{$stockColumn} + $quantity, 2);

        if ($newBalance < 0 && ! $allowNegative) {
            $name = $locked instanceof Material ? $locked->name : $locked->display_name;

            throw ValidationException::withMessages([
                'stock' => ($defect ? 'Stok cacat ' : 'Stok ')."{$name} tidak mencukupi (tersedia ".qty($locked->{$stockColumn}).', dibutuhkan '.qty(abs($quantity)).').',
            ]);
        }

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
