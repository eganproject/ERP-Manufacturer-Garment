<?php

namespace App\Models;

use App\Enums\ProductionStage;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'price' => 'decimal:2',
        'full_garment_rate' => 'decimal:2',
        'stage_route' => 'array',
        'is_active' => 'boolean',
    ];

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class)->orderBy('id');
    }

    public function boms()
    {
        return $this->hasMany(Bom::class);
    }

    /** Operasi / segmen borongan beserta tarif per pcs. */
    public function operations()
    {
        return $this->hasMany(ProductOperation::class)->orderBy('sequence')->orderBy('id');
    }

    /** Produk ini memakai upah borongan (tarif operasi atau tarif pcs jadi sudah diisi). */
    /**
     * Tahap produksi artikel ini berurutan (kosong = tahap standar potong → jahit → finishing → QC → packing).
     *
     * @return list<ProductionStage>
     */
    public function stages(): array
    {
        $route = ProductionStage::ordered($this->stage_route ?? []);

        return $route ?: ProductionStage::standard();
    }

    public function usesPiecework(): bool
    {
        return (float) $this->full_garment_rate > 0 || $this->operations()->where('is_active', true)->exists();
    }

    public function activeBom()
    {
        return $this->hasOne(Bom::class)->where('is_active', true)->latestOfMany();
    }
}
