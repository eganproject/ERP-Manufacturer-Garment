<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['created_at' => 'datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subject()
    {
        return $this->morphTo();
    }

    /**
     * Catat aktivitas pengguna terhadap sebuah dokumen.
     */
    public static function record(string $event, string $description, ?Model $subject = null): self
    {
        return static::create([
            'user_id' => auth()->id(),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'event' => $event,
            'description' => $description,
            'ip_address' => request()?->ip(),
        ]);
    }

    /**
     * Label jenis dokumen untuk ditampilkan di log.
     */
    public function subjectLabel(): ?string
    {
        return match ($this->subject_type) {
            'purchase_order' => 'PO',
            'goods_receipt' => 'Penerimaan',
            'sales_order' => 'SO',
            'delivery' => 'Surat Jalan',
            'production_order' => 'SPK',
            'user' => 'Pengguna',
            default => $this->subject_type,
        };
    }

    public function subjectUrl(): ?string
    {
        $subject = $this->subject;

        return match (true) {
            $subject instanceof PurchaseOrder => route('purchase-orders.show', $subject),
            $subject instanceof GoodsReceipt => route('purchase-orders.show', $subject->purchase_order_id),
            $subject instanceof SalesOrder => route('sales-orders.show', $subject),
            $subject instanceof Delivery => route('sales-orders.show', $subject->sales_order_id),
            $subject instanceof ProductionOrder => route('production-orders.show', $subject),
            default => null,
        };
    }
}
