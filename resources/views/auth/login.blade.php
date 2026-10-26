@php $company = \App\Models\Setting::company(); @endphp
<x-layouts.guest title="Masuk">
    <div class="grid min-h-full lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
        <div class="flex flex-col px-6 py-8 sm:px-12">
            <div class="flex items-center gap-3">
                <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-gray-900 text-white">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 3L4 6v4l3 1v10h10V11l3-1V6l-5-3a3 3 0 01-6 0z"/></svg>
                </div>
                <span class="text-sm font-semibold text-gray-900">{{ $company['name'] }}</span>
            </div>

            <div class="mx-auto flex w-full max-w-sm flex-1 flex-col justify-center py-12">
                <h1 class="text-2xl font-semibold tracking-tight text-gray-900">Selamat datang kembali</h1>
                <p class="mt-2 text-sm text-gray-500">Masuk ke {{ config('app.name') }} untuk melanjutkan pekerjaan Anda.</p>

                <form method="POST" action="{{ route('login') }}" class="mt-8 space-y-5">
                    @csrf
                    <x-form.input name="email" type="email" label="Email" required autofocus autocomplete="username" placeholder="nama@perusahaan.com" />
                    <div x-data="{ show: false }">
                        <x-form.input name="password" type="password" label="Password" required autocomplete="current-password" x-bind:type="show ? 'text' : 'password'" placeholder="••••••••" />
                        <button type="button" class="mt-1.5 text-xs font-medium text-gray-500 hover:text-gray-800" @click="show = !show" x-text="show ? 'Sembunyikan password' : 'Tampilkan password'">Tampilkan password</button>
                    </div>
                    <x-form.checkbox name="remember" label="Ingat saya di perangkat ini" />
                    <button type="submit" class="btn-primary h-10 w-full">Masuk</button>
                </form>
            </div>

            <p class="text-xs text-gray-400">© {{ now()->year }} {{ $company['name'] }}</p>
        </div>

        <div class="relative hidden overflow-hidden bg-gray-950 lg:block">
            <div class="absolute inset-0 opacity-[0.07]" style="background-image: linear-gradient(to right, #fff 1px, transparent 1px), linear-gradient(to bottom, #fff 1px, transparent 1px); background-size: 48px 48px;"></div>
            <div class="absolute -top-40 -right-40 h-[32rem] w-[32rem] rounded-full bg-indigo-600/30 blur-3xl"></div>
            <div class="relative flex h-full flex-col justify-end p-14 text-white">
                <p class="text-xs font-semibold tracking-[0.2em] text-indigo-300 uppercase">ERP Manufaktur Pakaian</p>
                <h2 class="mt-4 max-w-md text-3xl leading-tight font-semibold tracking-tight">Dari pesanan, produksi, sampai laporan keuangan — dalam satu sistem.</h2>
                <ul class="mt-10 grid max-w-lg grid-cols-2 gap-x-8 gap-y-4 text-sm text-gray-300">
                    @foreach (['Penjualan & marketplace', 'Pembelian & persetujuan PO', 'SPK produksi & maklon', 'Upah borongan & penggajian', 'Stok multi-gudang', 'Akuntansi & PPN otomatis'] as $feature)
                        <li class="flex items-center gap-2.5">
                            <svg class="h-4 w-4 shrink-0 text-indigo-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            {{ $feature }}
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</x-layouts.guest>
