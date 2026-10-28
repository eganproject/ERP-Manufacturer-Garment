<?php

namespace App\Services\Import;

use App\Models\Customer;

class CustomerImporter extends PartyImporter
{
    public function key(): string
    {
        return 'customers';
    }

    public function label(): string
    {
        return 'Pelanggan';
    }

    public function description(): string
    {
        return 'Daftar pelanggan (B2B / reseller) beserta kontak, NPWP & termin pembayaran.';
    }

    public function ability(): string
    {
        return 'customers.manage';
    }

    public function indexRoute(): ?string
    {
        return 'customers.index';
    }

    protected function model(): string
    {
        return Customer::class;
    }

    protected function prefix(): string
    {
        return 'PLG';
    }
}
