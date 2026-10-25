<?php

namespace App\Models;

use App\Models\Concerns\HasWarehouse;
use Illuminate\Database\Eloquent\Model;

/**
 * Surat jalan pengiriman barang jadi ke pelanggan.
 */
class Delivery extends Model
{
    use HasWarehouse;

    protected $guarded = ['id'];

    protected $casts = ['delivery_date' => 'date'];

    public function salesOrder()
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function items()
    {
        return $this->hasMany(DeliveryItem::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
