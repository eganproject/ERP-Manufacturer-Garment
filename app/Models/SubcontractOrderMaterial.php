<?php

namespace App\Models;

use App\Enums\SubcontractStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * Bahan yang dititipkan ke vendor maklon. Saldo di vendor = dikirim - diretur - terpakai.
 */
class SubcontractOrderMaterial extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'planned_quantity' => 'decimal:2',
        'sent_quantity' => 'decimal:2',
        'returned_quantity' => 'decimal:2',
        'used_quantity' => 'decimal:2',
        'unit_cost' => 'decimal:4',
    ];

    public function subcontractOrder()
    {
        return $this->belongsTo(SubcontractOrder::class);
    }

    public function material()
    {
        return $this->belongsTo(Material::class);
    }

    /** Bahan yang masih dipegang vendor. */
    public function balance(): float
    {
        return max(0, round((float) $this->sent_quantity - (float) $this->returned_quantity - (float) $this->used_quantity, 2));
    }

    public function balanceValue(): float
    {
        return round($this->balance() * (float) $this->unit_cost, 2);
    }

    /** Sisa rencana kirim. */
    public function unsentQuantity(): float
    {
        return max(0, round((float) $this->planned_quantity - (float) $this->sent_quantity, 2));
    }

    /**
     * Pemakaian standar BOM untuk pcs yang sudah diproses vendor.
     * Kebutuhan SPK per pcs × (hasil bagus + reject).
     */
    public function standardUsage(?ProductionMaterial $line = null, ?float $spkQuantity = null): float
    {
        $order = $this->subcontractOrder;
        $line ??= $order->productionOrder->materials->firstWhere('material_id', $this->material_id);
        $spkQuantity ??= (float) $order->productionOrder->quantity;

        // Hanya bahan yang memang dikirim ke vendor yang dihitung pemakaiannya
        if (! $line || $spkQuantity <= 0 || (float) $this->sent_quantity <= 0) {
            return 0.0;
        }

        return round((float) $line->required_quantity / $spkQuantity * $order->processedQuantity(), 2);
    }

    /** Pemakaian aktual: setelah ditutup = terpakai; selama berjalan = dikirim - diretur. */
    public function actualUsage(): float
    {
        return $this->subcontractOrder->status === SubcontractStatus::Closed
            ? (float) $this->used_quantity
            : round((float) $this->sent_quantity - (float) $this->returned_quantity, 2);
    }
}
