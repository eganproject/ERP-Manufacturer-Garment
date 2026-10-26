<x-layouts.app :title="$user->exists ? 'Ubah Pengguna' : 'Tambah Pengguna'">
    <x-card class="max-w-3xl">
        <form method="POST" action="{{ $user->exists ? route('users.update', $user) : route('users.store') }}" x-data="{ role: @js(old('role', $user->role?->value)) }">
            @csrf
            @if ($user->exists) @method('PUT') @endif
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-form.input name="name" label="Nama" :value="$user->name" required />
                <x-form.input name="email" type="email" label="Email" :value="$user->email" required />
                <div class="sm:col-span-2">
                    <x-form.select name="role" label="Peran" tip="Peran menentukan menu dan tombol apa saja yang bisa dipakai pengguna. Penjelasan tiap peran tampil di bawah pilihan." :options="\App\Enums\Role::options()" :value="$user->role" required x-model="role" :placeholder="false" />
                    @foreach (\App\Enums\Role::cases() as $role)
                        <p class="mt-1 text-xs text-gray-500" x-cloak x-show="role === '{{ $role->value }}'">{{ $role->description() }}</p>
                    @endforeach
                </div>
                <x-form.input name="password" type="password" label="Password" :required="! $user->exists" autocomplete="new-password"
                              :help="$user->exists ? 'Kosongkan jika tidak ingin mengganti password.' : 'Minimal 8 karakter.'" />
                <x-form.input name="password_confirmation" type="password" label="Ulangi Password" :required="! $user->exists" autocomplete="new-password" />
                <x-form.checkbox name="is_active" label="Aktif (boleh login)" tip="Nonaktifkan untuk karyawan yang keluar. Akun tidak bisa login, tetapi riwayat aktivitasnya tetap tersimpan." :checked="$user->is_active" />
            </div>
            <x-form.actions :cancel="route('users.index')" />
        </form>
    </x-card>
</x-layouts.app>
