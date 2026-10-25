<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Models\Concerns\HasActivityLog;
use App\Models\Concerns\Payable;
use Illuminate\Database\Eloquent\Model;

/**
 * Tagihan dari supplier (hutang), dicocokkan dengan PO & barang yang sudah diterima.
 */
class Bill extends Model
{
    use HasActivityLog, Payable;

    protected $guarded = ['id'];

    protected $casts = [
        'bill_date' => 'date',
        'due_date' => 'date',
        'status' => InvoiceStatus::class,
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'paid_amount' => 'decimal:2',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function subcontractOrder()
    {
        return $this->belongsTo(SubcontractOrder::class);
    }

    /** Dokumen sumber tagihan: PO (bahan) atau order maklon (jasa). */
    public function source(): PurchaseOrder|SubcontractOrder|null
    {
        return $this->purchaseOrder ?? $this->subcontractOrder;
    }

    public function items()
    {
        return $this->hasMany(BillItem::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function party(): Supplier
    {
        return $this->supplier;
    }
}
