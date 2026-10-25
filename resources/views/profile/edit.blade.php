<x-layouts.app title="Profil Saya">
    <div class="grid max-w-5xl grid-cols-1 gap-6 lg:grid-cols-2">
        <x-card title="Data Akun">
            <form method="POST" action="{{ route('profile.update') }}">
                @csrf
                @method('PUT')
                <div class="space-y-4">
                    <x-form.input name="name" label="Nama" :value="$user->name" required />
                    <x-form.input name="email" type="email" label="Email" :value="$user->email" required />
                    <div>
                        <span class="form-label">Peran</span>
                        <p class="py-1"><x-badge :color="$user->role->color()">{{ $user->role->label() }}</x-badge></p>
                        <p class="text-xs text-gray-500">{{ $user->role->description() }}</p>
                    </div>
                </div>
                <x-form.actions />
            </form>
        </x-card>

        <x-card title="Ganti Password">
            <form method="POST" action="{{ route('profile.password') }}">
                @csrf
                @method('PUT')
                <div class="space-y-4">
                    <x-form.input name="current_password" type="password" label="Password Saat Ini" required autocomplete="current-password" />
                    <x-form.input name="password" type="password" label="Password Baru" required autocomplete="new-password" help="Minimal 8 karakter." />
                    <x-form.input name="password_confirmation" type="password" label="Ulangi Password Baru" required autocomplete="new-password" />
                </div>
                <x-form.actions submit="Ganti Password" />
            </form>
        </x-card>
    </div>
</x-layouts.app>
