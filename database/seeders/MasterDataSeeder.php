<?php

namespace Database\Seeders;

use App\Enums\StockMovementType;
use App\Models\Bom;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Material;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\StockService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Data contoh untuk mencoba alur ERP garment.
 */
class MasterDataSeeder extends Seeder
{
    public function run(StockService $stock): void
    {
        DB::transaction(function () use ($stock) {
            $units = collect([
                ['code' => 'PCS', 'name' => 'Pieces'],
                ['code' => 'M', 'name' => 'Meter'],
                ['code' => 'YD', 'name' => 'Yard'],
                ['code' => 'KG', 'name' => 'Kilogram'],
                ['code' => 'ROLL', 'name' => 'Roll'],
                ['code' => 'CONE', 'name' => 'Cone (benang)'],
                ['code' => 'LSN', 'name' => 'Lusin'],
            ])->mapWithKeys(fn ($u) => [$u['code'] => Unit::firstOrCreate(['code' => $u['code']], $u)]);

            $cat = fn (string $name, string $type) => Category::firstOrCreate(['name' => $name, 'type' => $type]);

            $kain = $cat('Kain', 'material');
            $benang = $cat('Benang', 'material');
            $aksesoris = $cat('Aksesoris', 'material');
            $label = $cat('Label & Packaging', 'material');
            $kaos = $cat('Kaos', 'product');
            $kemeja = $cat('Kemeja', 'product');

            $materials = collect([
                ['code' => 'KN-CMB30-HTM', 'name' => 'Kain Cotton Combed 30s', 'color' => 'Hitam', 'category_id' => $kain->id, 'unit_id' => $units['KG']->id, 'price' => 125000, 'min_stock' => 50, 'stock' => 200],
                ['code' => 'KN-CMB30-PTH', 'name' => 'Kain Cotton Combed 30s', 'color' => 'Putih', 'category_id' => $kain->id, 'unit_id' => $units['KG']->id, 'price' => 118000, 'min_stock' => 50, 'stock' => 150],
                ['code' => 'KN-OXF-BRU', 'name' => 'Kain Oxford', 'color' => 'Biru Muda', 'category_id' => $kain->id, 'unit_id' => $units['M']->id, 'price' => 32000, 'min_stock' => 100, 'stock' => 400],
                ['code' => 'BNG-POL-HTM', 'name' => 'Benang Jahit Polyester', 'color' => 'Hitam', 'category_id' => $benang->id, 'unit_id' => $units['CONE']->id, 'price' => 18000, 'min_stock' => 20, 'stock' => 60],
                ['code' => 'BNG-POL-PTH', 'name' => 'Benang Jahit Polyester', 'color' => 'Putih', 'category_id' => $benang->id, 'unit_id' => $units['CONE']->id, 'price' => 18000, 'min_stock' => 20, 'stock' => 15],
                ['code' => 'RIB-HTM', 'name' => 'Rib Leher', 'color' => 'Hitam', 'category_id' => $kain->id, 'unit_id' => $units['KG']->id, 'price' => 95000, 'min_stock' => 10, 'stock' => 25],
                ['code' => 'KNC-12MM', 'name' => 'Kancing Kemeja 12mm', 'color' => 'Putih', 'category_id' => $aksesoris->id, 'unit_id' => $units['PCS']->id, 'price' => 250, 'min_stock' => 1000, 'stock' => 5000],
                ['code' => 'LBL-WOVEN', 'name' => 'Label Woven Merek', 'color' => null, 'category_id' => $label->id, 'unit_id' => $units['PCS']->id, 'price' => 600, 'min_stock' => 500, 'stock' => 3000],
                ['code' => 'PLS-OPP', 'name' => 'Plastik OPP Kemasan', 'color' => null, 'category_id' => $label->id, 'unit_id' => $units['PCS']->id, 'price' => 300, 'min_stock' => 500, 'stock' => 2000],
            ])->mapWithKeys(function ($row) use ($stock) {
                $initial = $row['stock'];
                unset($row['stock']);
                $material = Material::firstOrCreate(['code' => $row['code']], $row);

                if ($material->wasRecentlyCreated && $initial > 0) {
                    $stock->move($material, $initial, StockMovementType::Adjustment, notes: 'Saldo awal', unitCost: (float) $material->price);
                }

                return [$row['code'] => $material];
            });

            $textile = Supplier::firstOrCreate(['code' => 'SUP-001'], ['name' => 'PT Tekstil Nusantara', 'contact_person' => 'Budi', 'phone' => '0812-3456-7890', 'address' => 'Bandung, Jawa Barat']);
            $accessories = Supplier::firstOrCreate(['code' => 'SUP-002'], ['name' => 'CV Aksesoris Garmen Jaya', 'contact_person' => 'Sari', 'phone' => '0813-2222-3333', 'address' => 'Jakarta Pusat']);

            // Supplier utama untuk saran PO dari MRP
            foreach ($materials as $material) {
                if (! $material->default_supplier_id) {
                    $material->update(['default_supplier_id' => in_array($material->category_id, [$kain->id, $benang->id], true) ? $textile->id : $accessories->id]);
                }
            }
            Customer::firstOrCreate(['code' => 'CUS-001'], ['name' => 'Toko Busana Sejahtera', 'contact_person' => 'Andi', 'phone' => '0857-1111-2222', 'address' => 'Surabaya']);
            Customer::firstOrCreate(['code' => 'CUS-002'], ['name' => 'PT Seragam Mandiri', 'contact_person' => 'Rina', 'phone' => '0821-4444-5555', 'address' => 'Semarang']);

            $sizes = ['S', 'M', 'L', 'XL', 'XXL'];

            $tshirt = Product::firstOrCreate(['code' => 'TS-BSC'], [
                'name' => 'Kaos Basic Lengan Pendek', 'category_id' => $kaos->id, 'unit_id' => $units['PCS']->id, 'price' => 65000,
            ]);
            foreach ($sizes as $size) {
                $tshirt->variants()->firstOrCreate(['sku' => "TS-BSC-HTM-{$size}"], ['size' => $size, 'color' => 'Hitam']);
            }

            $shirt = Product::firstOrCreate(['code' => 'KMJ-OXF'], [
                'name' => 'Kemeja Oxford Lengan Panjang', 'category_id' => $kemeja->id, 'unit_id' => $units['PCS']->id, 'price' => 175000,
            ]);
            foreach ($sizes as $size) {
                $shirt->variants()->firstOrCreate(['sku' => "KMJ-OXF-BRU-{$size}"], ['size' => $size, 'color' => 'Biru Muda']);
            }

            if (! $tshirt->boms()->exists()) {
                $bom = Bom::create(['product_id' => $tshirt->id, 'name' => 'BOM Kaos Basic v1', 'is_active' => true, 'labor_cost' => 6000, 'overhead_cost' => 2500]);
                $bom->items()->createMany([
                    ['material_id' => $materials['KN-CMB30-HTM']->id, 'quantity' => 0.22, 'waste_percent' => 8, 'notes' => 'Badan + lengan'],
                    ['material_id' => $materials['RIB-HTM']->id, 'quantity' => 0.015, 'waste_percent' => 5],
                    ['material_id' => $materials['BNG-POL-HTM']->id, 'quantity' => 0.02, 'waste_percent' => 0],
                    ['material_id' => $materials['LBL-WOVEN']->id, 'quantity' => 1, 'waste_percent' => 0],
                    ['material_id' => $materials['PLS-OPP']->id, 'quantity' => 1, 'waste_percent' => 0],
                ]);
            }

            if (! $shirt->boms()->exists()) {
                $bom = Bom::create(['product_id' => $shirt->id, 'name' => 'BOM Kemeja Oxford v1', 'is_active' => true, 'labor_cost' => 15000, 'overhead_cost' => 4000]);
                $bom->items()->createMany([
                    ['material_id' => $materials['KN-OXF-BRU']->id, 'quantity' => 1.6, 'waste_percent' => 10, 'notes' => 'Badan, lengan, kerah'],
                    ['material_id' => $materials['BNG-POL-PTH']->id, 'quantity' => 0.03, 'waste_percent' => 0],
                    ['material_id' => $materials['KNC-12MM']->id, 'quantity' => 9, 'waste_percent' => 2],
                    ['material_id' => $materials['LBL-WOVEN']->id, 'quantity' => 1, 'waste_percent' => 0],
                    ['material_id' => $materials['PLS-OPP']->id, 'quantity' => 1, 'waste_percent' => 0],
                ]);
            }
        });
    }
}
