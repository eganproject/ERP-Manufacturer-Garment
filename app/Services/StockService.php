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

        $locked->forceFill(['stock' => $newBalance])->save();
        $item->setAttribute('stock', $newBalance);

        return $locked->stockMovements()->create([
            'date' => ($date ?? now())->format('Y-m-d'),
            'type' => $type,
            'quantity' => $quantity,
            'balance' => $newBalance,
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'reference_number' => $referenceNumber,
            'notes' => $notes,
            'user_id' => auth()->id(),
        ]);
    }
}
