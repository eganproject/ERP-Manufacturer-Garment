<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Unit extends Model
{
    protected $guarded = ['id'];

    public function materials()
    {
        return $this->hasMany(Material::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
