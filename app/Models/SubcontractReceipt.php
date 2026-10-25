<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Penerimaan hasil dari vendor maklon (TMK). Ongkos jasa = hasil bagus × tarif, dibebankan ke HPP SPK.
 */
class SubcontractReceipt extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'date',
        'good_quantity' => 'decimal:2',
        'reject_quantity' => 'decimal:2',
        'rate' => 'decimal:2',
        'amount' => 'decimal:2',
    ];

    public function subcontractOrder()
    {
        return $this->belongsTo(SubcontractOrder::class);
    }

    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }

    public function productionCost()
    {
        return $this->hasOne(ProductionCost::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
