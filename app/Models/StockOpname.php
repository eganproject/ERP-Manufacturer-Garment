<?php

namespace App\Models;

use App\Enums\StockOpnameStatus;
use App\Models\Concerns\HasActivityLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Stock opname per gudang (OPN-): daftar hitung fisik (draft) lalu diposting; selisih menjadi
 * penyesuaian stok di gudang itu (jurnal selisih persediaan).
 */
class StockOpname extends Model
{
    use HasActivityLog;

    public const SCOPES = ['materials' => 'Bahan Baku', 'products' => 'Barang Jadi'];

    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'date',
        'status' => StockOpnameStatus::class,
        'posted_at' => 'datetime',
    ];

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function items()
    {
        return $this->hasMany(StockOpnameItem::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function isDraft(): bool
    {
        return $this->status === StockOpnameStatus::Draft;
    }

    public function scopeLabel(): string
    {
        return self::SCOPES[$this->scope] ?? $this->scope;
    }
}
