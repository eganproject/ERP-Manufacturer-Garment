<?php

namespace App\Services\Import;

use App\Enums\EmployeeWorkType;
use App\Models\Employee;

class EmployeeImporter extends Importer
{
    use Concerns;

    public function key(): string
    {
        return 'employees';
    }

    public function label(): string
    {
        return 'Karyawan Produksi';
    }

    public function description(): string
    {
        return 'Penjahit & operator borongan beserta pola kerja (per pcs jadi / per operasi), NIK & rekening.';
    }

    public function ability(): string
    {
        return 'employees.manage';
    }

    public function indexRoute(): ?string
    {
        return 'employees.index';
    }

    public function columns(): array
    {
        $workTypes = [
            'Per pcs jadi' => EmployeeWorkType::Full, 'pcs jadi' => EmployeeWorkType::Full, 'komplit' => EmployeeWorkType::Full, 'full' => EmployeeWorkType::Full,
            EmployeeWorkType::Full->label() => EmployeeWorkType::Full,
            'Per operasi' => EmployeeWorkType::Operation, 'operasi' => EmployeeWorkType::Operation, 'segmen' => EmployeeWorkType::Operation, 'operation' => EmployeeWorkType::Operation,
            EmployeeWorkType::Operation->label() => EmployeeWorkType::Operation,
            'Keduanya' => EmployeeWorkType::Mixed, 'campuran' => EmployeeWorkType::Mixed, 'mixed' => EmployeeWorkType::Mixed,
        ];

        return [
            'code' => ['Kode', ['required' => true, 'max' => 30, 'example' => 'KRY-001', 'tip' => 'Kode / NIP karyawan. Kode yang sudah ada = datanya diperbarui.']],
            'name' => ['Nama', ['required' => true, 'example' => 'Siti Aminah']],
            'work_type' => ['Pola Kerja', ['type' => 'enum', 'options' => $workTypes, 'choices' => ['Per pcs jadi', 'Per operasi', 'Keduanya'], 'example' => 'Per operasi', 'tip' => 'Cara upah borongan dihitung. Kosong = Per operasi.']],
            'nik' => ['NIK', ['max' => 30, 'example' => '3273012345670001', 'tip' => 'Nomor KTP.']],
            'phone' => ['Telepon', ['max' => 30, 'example' => '081298765432', 'aliases' => ['No. HP', 'HP']]],
            'address' => ['Alamat', ['max' => 1000, 'example' => 'Cigondewah, Bandung']],
            'bank_account' => ['Rekening Bank', ['example' => 'BRI 1234-01-000123-50-1 a.n. Siti Aminah', 'tip' => 'Untuk transfer upah.', 'aliases' => ['Rekening']]],
            'join_date' => ['Tanggal Masuk', ['type' => 'date', 'example' => '01/03/2024']],
            'notes' => ['Catatan', ['max' => 1000, 'example' => '']],
            'is_active' => ['Aktif', ['type' => 'bool', 'example' => true, 'tip' => 'Kosong = Ya.']],
        ];
    }

    protected function save(array $row): string
    {
        $employee = Employee::where('code', $row['code'])->first() ?? new Employee(['code' => $row['code']]);

        return $this->upsert($employee, $row, ['work_type' => EmployeeWorkType::Operation, 'is_active' => true]);
    }

    public function exportRows(): iterable
    {
        $labels = [EmployeeWorkType::Full->value => 'Per pcs jadi', EmployeeWorkType::Operation->value => 'Per operasi', EmployeeWorkType::Mixed->value => 'Keduanya'];
        foreach (Employee::orderBy('code')->cursor() as $e) {
            yield [$e->code, $e->name, $labels[$e->work_type->value], $e->nik, $e->phone, $e->address, $e->bank_account, $e->join_date?->format('d/m/Y'), $e->notes, self::yesNo($e->is_active)];
        }
    }
}
