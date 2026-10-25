<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pemakaian nota kredit (retur penjualan) / nota debit (retur pembelian) untuk mengurangi sisa invoice / tagihan.
 */
class CreditApplication extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'applied_date' => 'date',
        'amount' => 'decimal:2',
    ];

    public function source()
    {
        return $this->morphTo();
    }

    public function payable()
    {
        return $this->morphTo();
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
