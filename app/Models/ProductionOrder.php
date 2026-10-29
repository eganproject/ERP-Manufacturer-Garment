<?php

namespace App\Models;

use App\Enums\ProductionOrderStatus;
use App\Enums\StageStatus;
use App\Models\Concerns\HasActivityLog;
use App\Models\Concerns\HasWarehouse;
use Illuminate\Database\Eloquent\Model;

class ProductionOrder extends Model
{
    use HasActivityLog, HasWarehouse;

    protected $guarded = ['id'];

    protected $casts = [
        'start_date' => 'date',
        'due_date' => 'date',
        'status' => ProductionOrderStatus::class,
        'quantity' => 'decimal:2',
        'finished_quantity' => 'decimal:2',
        'reject_quantity' => 'decimal:2',
        'defect_quantity' => 'decimal:2',
        'material_cost' => 'decimal:2',
        'conversion_cost' => 'decimal:2',
        'total_cost' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'materials_issued_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function bom()
    {
        return $this->belongsTo(Bom::class);
    }

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function items()
    {
        return $this->hasMany(ProductionOrderItem::class);
    }

    public function materials()
    {
        return $this->hasMany(ProductionMaterial::class);
    }

    public function stages()
    {
        return $this->hasMany(ProductionStage::class)->orderBy('sequence');
    }

    public function materialTransfers()
    {
        return $this->hasMany(ProductionMaterialTransfer::class)->orderBy('date')->orderBy('id');
    }

    public function receipts()
    {
        return $this->hasMany(ProductionReceipt::class)->orderBy('date')->orderBy('id');
    }

    /** Pcs yang sudah disetor ke gudang (bagus + grade B). */
    public function receivedQuantity(): float
    {
        $items = $this->relationLoaded('items') ? $this->items : $this->items()->get();

        return round((float) $items->sum('finished_quantity') + (float) $items->sum('defect_quantity'), 2);
    }

    public function costs()
    {
        return $this->hasMany(ProductionCost::class)->orderBy('date')->orderBy('id');
    }

    /** Biaya tambahan (CMT/upah/overhead) masih bisa dicatat sebelum SPK ditutup. */
    public function canRecordCosts(): bool
    {
        return in_array($this->status, [ProductionOrderStatus::Draft, ProductionOrderStatus::InProgress], true);
    }

    /**
     * Ringkasan HPP: estimasi standar BOM vs aktual (bahan dikeluarkan + biaya konversi).
     *
     * @return array{standard_material: float, standard_conversion: float, standard_total: float, standard_unit: float, material: float, conversion: float, total: float, unit: float, basis_quantity: float}
     */
    public function costSummary(): array
    {
        $this->loadMissing(['bom.items.material', 'materials.material', 'costs']);

        $planned = (float) $this->quantity;
        $this->loadMissing('items.variant');
        // Bahan standar per pcs = rata-rata tertimbang varian SPK (BOM bisa beda per ukuran / warna)
        $standardUnitMaterial = $planned > 0 && $this->items->isNotEmpty()
            ? $this->items->sum(fn (ProductionOrderItem $i) => (float) $i->quantity * $this->bom->standardMaterialCost($i->variant)) / $planned
            : $this->bom->standardMaterialCost();
        $standardUnitConversion = (float) $this->bom->labor_cost + (float) $this->bom->overhead_cost;

        $material = $this->status === ProductionOrderStatus::Completed
            ? (float) $this->material_cost
            : $this->materials->sum(fn (ProductionMaterial $line) => $line->cost());
        $conversion = (float) $this->costs->sum('amount');
        $total = $material + $conversion;

        // HPP per pcs dibebankan ke barang jadi bagus + grade B (reject yang dibuang ikut menanggung biaya)
        $basis = $this->status === ProductionOrderStatus::Completed ? (float) $this->finished_quantity + (float) $this->defect_quantity : $planned;

        return [
            'standard_material' => round($standardUnitMaterial * $planned, 2),
            'standard_conversion' => round($standardUnitConversion * $planned, 2),
            'standard_total' => round(($standardUnitMaterial + $standardUnitConversion) * $planned, 2),
            'standard_unit' => round($standardUnitMaterial + $standardUnitConversion, 2),
            'material' => round($material, 2),
            'conversion' => round($conversion, 2),
            'total' => round($total, 2),
            'unit' => $basis > 0 ? round($total / $basis, 2) : 0.0,
            'basis_quantity' => $basis,
        ];
    }

    public function pieceworkSheets()
    {
        return $this->hasMany(PieceworkSheet::class)->orderBy('date')->orderBy('id');
    }

    public function subcontractOrders()
    {
        return $this->hasMany(SubcontractOrder::class)->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isEditable(): bool
    {
        return $this->status === ProductionOrderStatus::Draft;
    }

    public function progressPercent(): int
    {
        $stages = $this->relationLoaded('stages') ? $this->stages : $this->stages()->get();

        if ($stages->isEmpty()) {
            return 0;
        }

        return (int) round($stages->where('status', StageStatus::Done)->count() / $stages->count() * 100);
    }
}
