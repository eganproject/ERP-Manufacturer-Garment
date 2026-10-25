<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CashTransactionLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['amount' => 'decimal:2'];

    public function cashTransaction()
    {
        return $this->belongsTo(CashTransaction::class);
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
