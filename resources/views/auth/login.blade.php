<x-layouts.guest title="Masuk">
    <div class="w-full max-w-sm">
        <div class="mb-6 text-center">
            <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-indigo-600 text-xl font-bold text-white">G</div>
            <h1 class="text-xl font-semibold text-gray-900">{{ config('app.name') }}</h1>
            <p class="text-sm text-gray-500">Masuk untuk melanjutkan</p>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <form method="POST" action="{{ route('login') }}" class="space-y-4">
                @csrf
                <x-form.input name="email" type="email" label="Email" required autofocus autocomplete="username" />
                <x-form.input name="password" type="password" label="Password" required autocomplete="current-password" />
                <x-form.checkbox name="remember" label="Ingat saya" />
                <button type="submit" class="btn-primary w-full">Masuk</button>
            </form>
        </div>
    </div>
</x-layouts.guest>
