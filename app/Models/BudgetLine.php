<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BudgetLine extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['amount' => 'decimal:2'];

    public function budget()
    {
        return $this->belongsTo(Budget::class);
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }
}
