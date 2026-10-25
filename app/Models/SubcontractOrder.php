<?php

namespace App\Models;

use App\Enums\ProductionStage as Stage;
use App\Enums\SubcontractStatus;
use App\Models\Concerns\HasActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Order maklon: sebagian / seluruh tahap SPK dikerjakan vendor luar dengan bahan dari perusahaan.
 */
class SubcontractOrder extends Model
{
    use HasActivityLog;

    protected $guarded = ['id'];

    protected $casts = [
        'order_date' => 'date',
        'due_date' => 'date',
        'stages' => 'array',
        'status' => SubcontractStatus::class,
        'quantity' => 'decimal:2',
        'rate' => 'decimal:2',
        'received_quantity' => 'decimal:2',
        'reject_quantity' => 'decimal:2',
        'service_cost' => 'decimal:2',
        'closed_at' => 'datetime',
    ];

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function materials()
    {
        return $this->hasMany(SubcontractOrderMaterial::class);
    }

    public function transfers()
    {
        return $this->hasMany(SubcontractTransfer::class)->orderBy('date')->orderBy('id');
    }

    public function receipts()
    {
        return $this->hasMany(SubcontractReceipt::class)->orderBy('date')->orderBy('id');
    }

    public function bills()
    {
        return $this->hasMany(Bill::class);
    }

    public function productionStages()
    {
        return $this->hasMany(ProductionStage::class)->orderBy('sequence');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return Collection<int, Stage> */
    public function stageEnums(): Collection
    {
        return collect($this->stages ?? [])->map(fn ($s) => Stage::from($s))
            ->sortBy(fn (Stage $s) => array_search($s, Stage::cases(), true))->values();
    }

    public function stagesLabel(): string
    {
        $stages = $this->stageEnums();

        return $stages->count() === count(Stage::cases())
            ? 'Full (semua tahap)'
            : $stages->map(fn (Stage $s) => $s->label())->implode(', ');
    }

    public function isOpen(): bool
    {
        return $this->status === SubcontractStatus::Open;
    }

    /** Sisa pcs yang belum dikembalikan vendor (bagus + reject). */
    public function outstandingQuantity(): float
    {
        return max(0, round((float) $this->quantity - (float) $this->received_quantity - (float) $this->reject_quantity, 2));
    }

    public function hasMaterialAtVendor(): bool
    {
        return $this->materials->contains(fn (SubcontractOrderMaterial $m) => $m->balance() > 0);
    }

    public function canCancel(): bool
    {
        return $this->isOpen() && $this->transfers()->doesntExist() && $this->receipts()->doesntExist();
    }

    public function unbilledReceipts()
    {
        return $this->receipts()->whereNull('bill_id')->where('amount', '>', 0);
    }

    public function canBill(): bool
    {
        return $this->status !== SubcontractStatus::Cancelled && $this->unbilledReceipts()->exists();
    }

    /** Jumlah pcs yang sudah diproses vendor (bagus + reject), dasar pemakaian bahan standar. */
    public function processedQuantity(): float
    {
        return (float) $this->received_quantity + (float) $this->reject_quantity;
    }
}
