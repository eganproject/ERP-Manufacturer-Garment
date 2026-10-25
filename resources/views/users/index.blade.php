<x-layouts.app title="Pengguna">
    <x-slot:actions><a href="{{ route('users.create') }}" class="btn-primary">+ Tambah Pengguna</a></x-slot:actions>

    <x-card :padding="false">
        <div class="border-b border-gray-200 p-4">
            <x-search-filter placeholder="Cari nama / email...">
                <div class="w-48">
                    <x-form.select name="role" :options="\App\Enums\Role::options()" :value="request('role')" placeholder="Semua peran" />
                </div>
            </x-search-filter>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>Nama</th><th>Email</th><th>Peran</th><th>Login Terakhir</th><th>Status</th><th class="text-right">Aksi</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($users as $user)
                        <tr>
                            <td class="font-medium">{{ $user->name }} @if ($user->is(auth()->user()))<span class="text-xs text-gray-400">(Anda)</span>@endif</td>
                            <td>{{ $user->email }}</td>
                            <td><x-badge :color="$user->role->color()">{{ $user->role->label() }}</x-badge></td>
                            <td>{{ $user->last_login_at ? $user->last_login_at->translatedFormat('d M Y H:i') : '-' }}</td>
                            <td><x-badge :color="$user->is_active ? 'green' : 'gray'">{{ $user->is_active ? 'Aktif' : 'Nonaktif' }}</x-badge></td>
                            <td class="space-x-3 text-right whitespace-nowrap">
                                <a href="{{ route('users.edit', $user) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Ubah</a>
                                @unless ($user->is(auth()->user()))
                                    <x-delete-button :action="route('users.destroy', $user)" />
                                @endunless
                            </td>
                        </tr>
                    @empty
                        <x-empty-row colspan="6" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4">{{ $users->links() }}</div>
    </x-card>

    <x-card title="Hak Akses per Peran" class="mt-6" :padding="false">
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead>
                    <tr>
                        <th>Hak Akses</th>
                        @foreach (\App\Enums\Role::cases() as $role)
                            <th class="text-center">{{ $role->label() }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach (\App\Enums\Role::abilities() as $ability => $label)
                        <tr>
                            <td>{{ $label }}</td>
                            @foreach (\App\Enums\Role::cases() as $role)
                                <td class="text-center">
                                    @if ($role->allows($ability))
                                        <span class="font-semibold text-emerald-600">✓</span>
                                    @else
                                        <span class="text-gray-300">–</span>
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-card>
</x-layouts.app>
