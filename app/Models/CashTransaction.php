<?php

namespace App\Models;

use App\Enums\CashTransactionType;
use App\Enums\PaymentStatus;
use App\Models\Concerns\HasActivityLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Bukti kas masuk / kas keluar / transfer antar rekening kas & bank.
 * Kas masuk/keluar memiliki baris lawan (akun pendapatan, beban, aset, pinjaman, modal, dst).
 */
class CashTransaction extends Model
{
    use HasActivityLog;

    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'date',
        'type' => CashTransactionType::class,
        'status' => PaymentStatus::class,
        'amount' => 'decimal:2',
    ];

    public function cashAccount()
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }

    public function toAccount()
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }

    public function lines()
    {
        return $this->hasMany(CashTransactionLine::class)->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCancelled(): bool
    {
        return $this->status === PaymentStatus::Cancelled;
    }
}
