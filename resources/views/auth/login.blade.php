@php $company = \App\Models\Setting::company(); @endphp
<x-layouts.guest title="Masuk">
    <div class="flex min-h-full items-center justify-center bg-gray-50 px-4 py-12">
        <div class="w-full max-w-sm">
            <div class="mb-6 flex flex-col items-center text-center">
                @if ($company['logo'])
                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($company['logo']) }}" alt="" class="h-10 w-auto object-contain">
                @else
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-600 text-white">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 3L4 6v4l3 1v10h10V11l3-1V6l-5-3a3 3 0 01-6 0z"/></svg>
                    </span>
                @endif
                <h1 class="mt-6 text-xl font-semibold text-gray-900">Masuk ke akun Anda</h1>
                <p class="mt-1 text-sm text-gray-500">{{ $company['name'] }} · ERP Manufaktur Pakaian</p>
            </div>

            <div class="card p-6">
                <form method="POST" action="{{ route('login') }}" class="space-y-4" x-data="{ loading: false, show: false }" @submit="loading = true">
                    @csrf
                    <x-form.input name="email" type="email" label="Email" required autofocus autocomplete="username" placeholder="nama@perusahaan.com" />
                    <div>
                        <x-form.input name="password" type="password" label="Password" required autocomplete="current-password" x-bind:type="show ? 'text' : 'password'" placeholder="••••••••" />
                        <button type="button" class="mt-1.5 text-xs text-gray-500 hover:text-gray-800" @click="show = !show" x-text="show ? 'Sembunyikan password' : 'Tampilkan password'">Tampilkan password</button>
                    </div>
                    <x-form.checkbox name="remember" label="Ingat saya di perangkat ini" />
                    <button type="submit" class="btn-primary w-full" :disabled="loading">
                        <span x-text="loading ? 'Memproses...' : 'Masuk'">Masuk</span>
                    </button>
                </form>
            </div>

            <p class="mt-4 text-center text-xs text-gray-500">Lupa password? Hubungi administrator untuk direset.</p>
            <p class="mt-6 text-center text-xs text-gray-400">© {{ now()->year }} {{ $company['name'] }}</p>
        </div>
    </div>
</x-layouts.guest>
