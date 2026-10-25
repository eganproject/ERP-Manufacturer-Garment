<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'is_subcontractor' => 'boolean', 'payment_terms' => 'integer'];

    public function purchaseOrders()
    {
        return $this->hasMany(PurchaseOrder::class);
    }

    public function subcontractOrders()
    {
        return $this->hasMany(SubcontractOrder::class);
    }

    /** Vendor maklon (CMT / jasa jahit / potong) yang menerima titipan bahan. */
    public function scopeSubcontractors($query)
    {
        return $query->where('is_subcontractor', true);
    }

    public function bills()
    {
        return $this->hasMany(Bill::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }
}
