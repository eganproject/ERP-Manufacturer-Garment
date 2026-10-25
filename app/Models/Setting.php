<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Pengaturan aplikasi berbentuk key-value (profil perusahaan, dsb).
 */
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    private const CACHE_KEY = 'app_settings';

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = static::all_()[$key] ?? null;

        return ($value === null || $value === '') ? $default : $value;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function put(array $values): void
    {
        foreach ($values as $key => $value) {
            static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        }

        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array<string, string|null>
     */
    public static function all_(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->pluck('value', 'key')->all());
    }

    /**
     * Profil perusahaan untuk kop dokumen.
     *
     * @return array<string, string|null>
     */
    public static function company(): array
    {
        return [
            'name' => static::get('company_name', config('app.name')),
            'address' => static::get('company_address'),
            'phone' => static::get('company_phone'),
            'email' => static::get('company_email'),
            'npwp' => static::get('company_npwp'),
            'logo' => static::get('company_logo'),
            'city' => static::get('company_city'),
        ];
    }

    /**
     * Pengaturan invoice: PPN default dan rekening tujuan pembayaran.
     *
     * @return array{tax_rate: float, bank_info: string|null, invoice_notes: string|null}
     */
    public static function invoice(): array
    {
        return [
            'tax_rate' => (float) static::get('invoice_tax_rate', 0),
            'bank_info' => static::get('invoice_bank_info'),
            'invoice_notes' => static::get('invoice_notes'),
        ];
    }
}
