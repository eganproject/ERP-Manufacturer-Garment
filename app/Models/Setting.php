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
            'website' => static::get('company_website'),
            'footer' => static::get('document_footer'),
        ];
    }

    /** Penanda tangan tetap pada dokumen cetak: kunci => [label pengaturan, jabatan default]. */
    public const SIGNERS = [
        'director' => ['Pimpinan / Direktur', 'Direktur'],
        'finance' => ['Keuangan', 'Keuangan'],
        'purchasing' => ['Pembelian', 'Pembelian'],
        'warehouse' => ['Kepala Gudang', 'Kepala Gudang'],
        'production' => ['Kepala Produksi', 'Kepala Produksi'],
    ];

    /**
     * Nama & jabatan penanda tangan dokumen. Nama kosong = kolom tanda tangan dibiarkan titik-titik.
     *
     * @return array<string, array{name: string|null, title: string}>
     */
    public static function signers(): array
    {
        $signers = [];
        foreach (self::SIGNERS as $key => [, $title]) {
            $signers[$key] = [
                'name' => static::get("signer_{$key}_name"),
                'title' => static::get("signer_{$key}_title", $title),
            ];
        }

        return $signers;
    }

    /**
     * Syarat & ketentuan default yang dicetak di Purchase Order.
     */
    public static function purchaseTerms(): ?string
    {
        return static::get('purchase_terms');
    }

    /**
     * Batas nilai PO yang wajib disetujui sebelum dipesan ke supplier. 0 = tanpa persetujuan.
     */
    public static function poApprovalThreshold(): float
    {
        return max(0, (float) static::get('po_approval_threshold', 0));
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

    /**
     * Penawaran harga: masa berlaku default (hari) dan syarat & ketentuan default.
     *
     * @return array{validity_days: int, terms: string|null}
     */
    public static function quotation(): array
    {
        return [
            'validity_days' => max(1, (int) static::get('quotation_validity_days', 14)),
            'terms' => static::get('quotation_terms'),
        ];
    }

    /** Siklus penggajian borongan yang tersedia. */
    public const PAYROLL_CYCLES = ['weekly' => 'Mingguan (7 hari)', 'biweekly' => 'Dua mingguan (14 hari)', 'monthly' => 'Bulanan'];

    /**
     * Pengaturan penggajian borongan: siklus (default mingguan) dan hari awal periode.
     *
     * @return array{cycle: string, week_start: int}
     */
    public static function payroll(): array
    {
        $cycle = (string) static::get('payroll_cycle', 'weekly');

        return [
            'cycle' => array_key_exists($cycle, self::PAYROLL_CYCLES) ? $cycle : 'weekly',
            'week_start' => min(7, max(1, (int) static::get('payroll_week_start', 1))), // 1 = Senin ... 7 = Minggu
        ];
    }
}
