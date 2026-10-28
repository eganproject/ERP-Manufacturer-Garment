<?php

namespace App\Services\Import;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Model;

class SupplierImporter extends PartyImporter
{
    public function key(): string
    {
        return 'suppliers';
    }

    public function label(): string
    {
        return 'Supplier & Vendor Maklon';
    }

    public function description(): string
    {
        return 'Pemasok bahan dan vendor maklon (penjahit luar) beserta kontak & termin pembayaran.';
    }

    public function ability(): string
    {
        return 'suppliers.manage';
    }

    public function indexRoute(): ?string
    {
        return 'suppliers.index';
    }

    protected function model(): string
    {
        return Supplier::class;
    }

    protected function prefix(): string
    {
        return 'SUP';
    }

    public function columns(): array
    {
        return parent::columns() + [
            'is_subcontractor' => ['Vendor Maklon', ['type' => 'bool', 'example' => false, 'tip' => 'Ya = vendor jasa jahit/maklon (bisa dipilih di Order Maklon). Kosong = Tidak.', 'aliases' => ['Maklon']]],
        ];
    }

    protected function defaults(): array
    {
        return ['is_subcontractor' => false];
    }

    protected function extraExport(Model $model): array
    {
        return [self::yesNo($model->is_subcontractor)];
    }
}
