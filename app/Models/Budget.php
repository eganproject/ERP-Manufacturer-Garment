<?php

namespace App\Models;

use App\Models\Concerns\HasActivityLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Anggaran satu tahun buku: nilai per akun per bulan (positif menurut saldo normal akun).
 */
class Budget extends Model
{
    use HasActivityLog;

    protected $guarded = ['id'];

    public function lines()
    {
        return $this->hasMany(BudgetLine::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Nilai anggaran per akun: [account_id => [1 => jumlah, ..., 12 => jumlah]].
     *
     * @return array<int, array<int, float>>
     */
    public function matrix(): array
    {
        $matrix = [];

        foreach ($this->lines as $line) {
            $matrix[$line->account_id] ??= array_fill(1, 12, 0.0);
            $matrix[$line->account_id][$line->month] = (float) $line->amount;
        }

        return $matrix;
    }
}
