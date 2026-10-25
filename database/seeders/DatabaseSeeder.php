<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Satu akun contoh per peran, password: password
        $users = [
            'admin@erp.test' => ['Administrator', Role::Admin],
            'purchasing@erp.test' => ['Staf Purchasing', Role::Purchasing],
            'gudang@erp.test' => ['Staf Gudang', Role::Gudang],
            'produksi@erp.test' => ['Staf Produksi (PPIC)', Role::Produksi],
            'sales@erp.test' => ['Staf Sales', Role::Sales],
        ];

        foreach ($users as $email => [$name, $role]) {
            User::updateOrCreate(['email' => $email], ['name' => $name, 'password' => 'password', 'role' => $role, 'is_active' => true]);
        }

        if (Setting::get('company_name') === null) {
            Setting::put([
                'company_name' => 'CV Garmen Contoh',
                'company_address' => 'Jl. Industri No. 10, Bandung',
                'company_city' => 'Bandung',
                'company_phone' => '022-1234567',
                'company_email' => 'admin@garmen.test',
            ]);
        }

        $this->call(MasterDataSeeder::class);
    }
}
