<?php

namespace App\Models;

use App\Models\Concerns\HasWarehouse;
use Illuminate\Database\Eloquent\Model;

class GoodsReceipt extends Model
{
    use HasWarehouse;

    protected $guarded = ['id'];

    protected $casts = ['receipt_date' => 'date'];

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function items()
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
