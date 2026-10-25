<?php

namespace App\Services;

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
    ): StockMovement {
        /** @var Material|ProductVariant $locked */
        $locked = $item->newQuery()->lockForUpdate()->findOrFail($item->getKey());
        $newBalance = round((float) $locked->stock + $quantity, 2);

        if ($newBalance < 0 && ! $allowNegative) {
            $name = $locked instanceof Material ? $locked->name : $locked->display_name;

            throw ValidationException::withMessages([
                'stock' => "Stok {$name} tidak mencukupi (tersedia ".qty($locked->stock).', dibutuhkan '.qty(abs($quantity)).').',
            ]);
        }

        $currentCost = $locked instanceof Material ? $locked->costPrice() : (float) $locked->average_cost;
        $attributes = ['stock' => $newBalance];

        if ($quantity > 0 && $unitCost !== null) {
            $previousQty = max(0, (float) $locked->stock);
            $averageCost = $previousQty > 0 && $currentCost > 0
                ? ($previousQty * $currentCost + $quantity * $unitCost) / ($previousQty + $quantity)
                : $unitCost;
            $attributes['average_cost'] = round($averageCost, $locked instanceof Material ? 4 : 2);
        }

        $locked->forceFill($attributes)->save();

        foreach ($attributes as $key => $value) {
            $item->setAttribute($key, $value);
        }

        return $locked->stockMovements()->create([
            'date' => ($date ?? now())->format('Y-m-d'),
            'type' => $type,
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
