<?php

namespace Database\Seeders;

use App\Enums\EmployeeWorkType;
use App\Models\Employee;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Contoh karyawan borongan dan tarif per operasi untuk data demo.
 */
class PieceworkDemoSeeder extends Seeder
{
    public function run(): void
    {
        $rates = [
            'TS-BSC' => [3500, [
                ['Potong', 'cutting', 400], ['Obras bahu', 'sewing', 300], ['Pasang lengan', 'sewing', 600],
                ['Obras samping', 'sewing', 500], ['Pasang rib leher', 'sewing', 500], ['Kelim bawah & lengan', 'sewing', 500],
                ['Buang benang & lipat', 'finishing', 300], ['Packing', 'packing', 200],
            ]],
            'KMJ-OXF' => [12000, [
                ['Potong', 'cutting', 1000], ['Jahit kerah', 'sewing', 2500], ['Pasang manset', 'sewing', 1500],
                ['Jahit badan & lengan', 'sewing', 3000], ['Lubang & pasang kancing', 'finishing', 1500],
                ['Buang benang, setrika & lipat', 'finishing', 1000], ['Packing', 'packing', 300],
            ]],
        ];

        foreach ($rates as $code => [$full, $operations]) {
            $product = Product::where('code', $code)->first();

            if (! $product || $product->operations()->exists()) {
                continue;
            }

            foreach ($operations as $i => [$name, $stage, $rate]) {
                $product->operations()->create(['name' => $name, 'stage' => $stage, 'rate' => $rate, 'sequence' => $i + 1]);
            }

            $product->update(['full_garment_rate' => $full]);
        }

        $employees = [
            ['KRY-001', 'Siti Aminah', EmployeeWorkType::Operation],
            ['KRY-002', 'Dewi Lestari', EmployeeWorkType::Operation],
            ['KRY-003', 'Rina Marlina', EmployeeWorkType::Operation],
            ['KRY-004', 'Asep Saepudin', EmployeeWorkType::Full],
            ['KRY-005', 'Yuyun Wahyuni', EmployeeWorkType::Mixed],
        ];

        foreach ($employees as [$code, $name, $type]) {
            Employee::firstOrCreate(['code' => $code], ['name' => $name, 'work_type' => $type, 'join_date' => '2025-01-06', 'is_active' => true]);
        }
    }
}
