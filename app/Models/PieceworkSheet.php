<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Lembar hasil kerja borongan harian untuk satu SPK.
 */
class PieceworkSheet extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'date',
        'total_amount' => 'decimal:2',
    ];

    public function productionOrder()
    {
        return $this->belongsTo(ProductionOrder::class);
    }

    public function entries()
    {
        return $this->hasMany(PieceworkEntry::class)->orderBy('id');
    }

    public function cost()
    {
        return $this->hasOne(ProductionCost::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Hasil kerja yang sudah masuk penggajian tidak boleh diubah. */
    public function isLocked(): bool
    {
        return $this->entries->contains(fn (PieceworkEntry $e) => $e->payroll_line_id !== null);
    }
}
