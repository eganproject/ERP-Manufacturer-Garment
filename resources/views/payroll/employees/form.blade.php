<x-layouts.app :title="$employee->exists ? 'Ubah Karyawan' : 'Tambah Karyawan'">
    <x-card class="max-w-3xl">
        <form method="POST" action="{{ $employee->exists ? route('employees.update', $employee) : route('employees.store') }}">
            @csrf
            @if ($employee->exists) @method('PUT') @endif
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-form.input name="code" label="Kode / NIP" :value="$employee->code" required />
                <x-form.input name="name" label="Nama" :value="$employee->name" required />
                <x-form.select name="work_type" label="Pola Kerja" :options="\App\Enums\EmployeeWorkType::options()" :value="$employee->work_type" :placeholder="false" required
                    help="Per pcs jadi: satu orang mengerjakan baju dari awal sampai jadi. Per operasi: mengerjakan segmen tertentu (mis. pasang lengan)." />
                <x-form.input name="join_date" label="Tanggal Masuk" :value="$employee->join_date" data-date />
                <x-form.input name="nik" label="NIK KTP" :value="$employee->nik" />
                <x-form.input name="phone" label="Telepon / WA" :value="$employee->phone" data-phone placeholder="08xx-xxxx-xxxx" />
                <x-form.input name="bank_account" label="Rekening (untuk transfer upah)" :value="$employee->bank_account" placeholder="BRI 1234-5678 a.n. ..." class="sm:col-span-2" />
                <x-form.textarea name="address" label="Alamat" :value="$employee->address" rows="2" class="sm:col-span-2" />
                <x-form.textarea name="notes" label="Catatan" :value="$employee->notes" rows="2" class="sm:col-span-2" />
                <x-form.checkbox name="is_active" label="Aktif (bisa dicatat hasil kerjanya)" :checked="$employee->is_active" class="sm:col-span-2" />
            </div>
            <x-form.actions :cancel="$employee->exists ? route('employees.show', $employee) : route('employees.index')" />
        </form>
    </x-card>
</x-layouts.app>
