<?php

namespace App\Models;

use App\Enums\DefectHandlingType;
use App\Enums\ReturnStatus;
use App\Models\Concerns\HasActivityLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Penanganan barang cacat (BC-): pindah ke stok cacat, perbaiki, kembalikan ke stok jual, atau musnahkan.
 */
class DefectHandling extends Model
{
    use HasActivityLog;

    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'date',
        'type' => DefectHandlingType::class,
        'status' => ReturnStatus::class,
        'total_cost' => 'decimal:2',
        'extra_cost' => 'decimal:2',
        'cash_account_id' => 'integer',
    ];

    public function items()
    {
        return $this->hasMany(DefectHandlingItem::class)->orderBy('id');
    }

    public function cashAccount()
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCancelled(): bool
    {
        return $this->status === ReturnStatus::Cancelled;
    }

    public function totalQuantity(): float
    {
        return round((float) $this->items->sum('quantity'), 2);
    }
}
