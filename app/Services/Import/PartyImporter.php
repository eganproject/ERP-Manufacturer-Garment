<?php

namespace App\Services\Import;

use Illuminate\Database\Eloquent\Model;

/**
 * Dasar impor pelanggan & supplier (kolom identitas yang sama).
 */
abstract class PartyImporter extends Importer
{
    use Concerns;

    /** @return class-string<Model> */
    abstract protected function model(): string;

    abstract protected function prefix(): string;

    public function columns(): array
    {
        return [
            'code' => ['Kode', ['required' => true, 'max' => 30, 'example' => $this->prefix().'-001', 'tip' => 'Kode unik. Kode yang sudah ada di sistem = datanya diperbarui.']],
            'name' => ['Nama', ['required' => true, 'example' => 'CV Maju Jaya']],
            'contact_person' => ['Kontak', ['example' => 'Bu Rina', 'tip' => 'Nama orang yang dihubungi.']],
            'phone' => ['Telepon', ['max' => 30, 'example' => '081234567890', 'aliases' => ['No. HP', 'HP', 'No Telepon']]],
            'email' => ['Email', ['example' => 'admin@majujaya.co.id']],
            'address' => ['Alamat', ['max' => 1000, 'example' => 'Jl. Soekarno-Hatta No. 10, Bandung']],
            'tax_number' => ['NPWP', ['max' => 30, 'example' => '01.234.567.8-901.000']],
            'payment_terms' => ['Termin (hari)', ['type' => 'integer', 'min' => 0, 'example' => 30, 'tip' => 'Jatuh tempo tagihan dalam hari. Kosong = 30 hari.', 'aliases' => ['Termin', 'TOP']]],
            'is_active' => ['Aktif', ['type' => 'bool', 'example' => true, 'tip' => 'Kosong = Ya.']],
        ];
    }

    protected function check(array $row, array &$context): array
    {
        $errors = [];
        if ($row['email'] !== null && ! filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = "email \"{$row['email']}\" tidak valid";
        }
        if ($row['payment_terms'] !== null && $row['payment_terms'] > 365) {
            $errors[] = 'termin maksimal 365 hari';
        }

        return $errors;
    }

    protected function save(array $row): string
    {
        $class = $this->model();
        $model = $class::where('code', $row['code'])->first() ?? new $class(['code' => $row['code']]);

        return $this->upsert($model, $row, ['payment_terms' => 30, 'is_active' => true] + $this->defaults());
    }

    /** @return array<string, mixed> */
    protected function defaults(): array
    {
        return [];
    }

    public function exportRows(): iterable
    {
        $class = $this->model();
        foreach ($class::orderBy('code')->cursor() as $m) {
            yield array_merge([
                $m->code, $m->name, $m->contact_person, $m->phone, $m->email, $m->address, $m->tax_number, (int) $m->payment_terms, self::yesNo($m->is_active),
            ], $this->extraExport($m));
        }
    }

    /** @return list<mixed> */
    protected function extraExport(Model $model): array
    {
        return [];
    }
}
