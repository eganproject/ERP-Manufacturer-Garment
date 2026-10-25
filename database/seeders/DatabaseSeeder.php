<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@erp.test'],
            ['name' => 'Administrator', 'password' => 'password'],
        );

        $this->call(MasterDataSeeder::class);
    }
}
