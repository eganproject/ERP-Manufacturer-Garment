<?php

namespace App\Services\Import;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * Daftar jenis impor data yang tersedia di menu Impor Data.
 */
class ImportRegistry
{
    /** @return array<string, class-string<Importer>> */
    public static function types(): array
    {
        return [
            'products' => ProductImporter::class,
            'materials' => MaterialImporter::class,
            'customers' => CustomerImporter::class,
            'suppliers' => SupplierImporter::class,
            'employees' => EmployeeImporter::class,
            'opening-stock' => OpeningStockImporter::class,
            'journals' => JournalImporter::class,
        ];
    }

    public static function find(string $key): ?Importer
    {
        $class = self::types()[$key] ?? null;

        return $class ? app($class) : null;
    }

    /** @return list<Importer> */
    public static function allowed(?Authenticatable $user = null): array
    {
        return collect(self::types())
            ->map(fn ($class) => app($class))
            ->filter(fn (Importer $importer) => Gate::forUser($user ?? auth()->user())->allows($importer->ability()))
            ->values()
            ->all();
    }
}
