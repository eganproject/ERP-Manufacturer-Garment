@props(['title' => null])

@php
    $menu = [
        ['label' => 'Dashboard', 'route' => 'dashboard', 'active' => 'dashboard', 'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
        ['heading' => 'Transaksi'],
        ['label' => 'Penjualan (SO)', 'route' => 'sales-orders.index', 'active' => 'sales-orders.*', 'icon' => 'M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z'],
        ['label' => 'Produksi (SPK)', 'route' => 'production-orders.index', 'active' => 'production-orders.*', 'icon' => 'M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z'],
        ['label' => 'Pembelian (PO)', 'route' => 'purchase-orders.index', 'active' => 'purchase-orders.*', 'icon' => 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z'],
        ['heading' => 'Persediaan'],
        ['label' => 'Stok Bahan Baku', 'route' => 'stock.materials', 'active' => 'stock.materials*', 'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'],
        ['label' => 'Stok Barang Jadi', 'route' => 'stock.products', 'active' => 'stock.products*', 'icon' => 'M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4'],
        ['label' => 'Penyesuaian Stok', 'route' => 'stock.adjustment', 'active' => 'stock.adjustment*', 'icon' => 'M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4'],
        ['heading' => 'Master Data'],
        ['label' => 'Produk', 'route' => 'products.index', 'active' => 'products.*', 'icon' => 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z'],
        ['label' => 'Bill of Materials', 'route' => 'boms.index', 'active' => 'boms.*', 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01'],
        ['label' => 'Bahan Baku', 'route' => 'materials.index', 'active' => 'materials.*', 'icon' => 'M4 6h16M4 10h16M4 14h16M4 18h16'],
        ['label' => 'Pelanggan', 'route' => 'customers.index', 'active' => 'customers.*', 'icon' => 'M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z'],
        ['label' => 'Supplier', 'route' => 'suppliers.index', 'active' => 'suppliers.*', 'icon' => 'M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4'],
        ['label' => 'Kategori', 'route' => 'categories.index', 'active' => 'categories.*', 'icon' => 'M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z'],
        ['label' => 'Satuan', 'route' => 'units.index', 'active' => 'units.*', 'icon' => 'M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3'],
    ];

    $validationHtml = $errors->any()
        ? '<ul style="text-align:left">'.collect($errors->all())->unique()->take(8)->map(fn ($e) => '<li>• '.e($e).'</li>')->implode('').'</ul>'
        : null;
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full bg-gray-100">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-full font-sans antialiased" x-data="{ sidebarOpen: false }">
    <div id="flash-messages" class="hidden"
         data-success="{{ session('success') }}"
         data-error="{{ session('error') }}"
         data-validation="{{ $validationHtml }}"></div>

    {{-- Overlay mobile --}}
    <div x-cloak x-show="sidebarOpen" x-transition.opacity class="fixed inset-0 z-30 bg-gray-900/50 lg:hidden" @click="sidebarOpen = false"></div>

    {{-- Sidebar --}}
    <aside class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col bg-slate-900 text-slate-300 transition-transform lg:translate-x-0"
           :class="{ 'translate-x-0': sidebarOpen, '-translate-x-full': !sidebarOpen }">
        <div class="flex h-16 shrink-0 items-center gap-2 border-b border-slate-800 px-5">
            <div class="flex h-9 w-9 items-center justify-center rounded-lg bg-indigo-500 font-bold text-white">G</div>
            <div>
                <p class="text-sm font-semibold text-white">{{ config('app.name') }}</p>
                <p class="text-xs text-slate-400">Manufaktur Pakaian</p>
            </div>
        </div>
        <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 py-4">
            @foreach ($menu as $item)
                @if (isset($item['heading']))
                    <p class="px-3 pt-4 pb-1 text-[11px] font-semibold tracking-wider text-slate-500 uppercase">{{ $item['heading'] }}</p>
                @else
                    @php $active = request()->routeIs($item['active']); @endphp
                    <a href="{{ route($item['route']) }}"
                       @class([
                           'flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition',
                           'bg-slate-800 font-medium text-white' => $active,
                           'hover:bg-slate-800/60 hover:text-white' => ! $active,
                       ])>
                        <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}"/></svg>
                        {{ $item['label'] }}
                    </a>
                @endif
            @endforeach
        </nav>
    </aside>

    <div class="lg:pl-64">
        {{-- Topbar --}}
        <header class="sticky top-0 z-20 flex h-16 items-center gap-4 border-b border-gray-200 bg-white px-4 sm:px-6">
            <button type="button" class="rounded-md p-2 text-gray-500 hover:bg-gray-100 lg:hidden" @click="sidebarOpen = true">
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <h1 class="flex-1 truncate text-lg font-semibold text-gray-800">{{ $title }}</h1>

            <div class="relative" x-data="{ open: false }">
                <button type="button" class="flex items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-gray-100" @click="open = !open" @click.outside="open = false">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-indigo-100 font-semibold text-indigo-700">{{ strtoupper(substr(auth()->user()->name, 0, 1)) }}</span>
                    <span class="hidden text-gray-700 sm:block">{{ auth()->user()->name }}</span>
                </button>
                <div x-cloak x-show="open" x-transition class="absolute right-0 mt-2 w-48 rounded-lg border border-gray-200 bg-white py-1 shadow-lg">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="w-full px-4 py-2 text-left text-sm text-gray-700 hover:bg-gray-50">Keluar</button>
                    </form>
                </div>
            </div>
        </header>

        <main class="p-4 sm:p-6">
            @isset($actions)
                <div class="mb-4 flex flex-wrap items-center justify-end gap-2">{{ $actions }}</div>
            @endisset

            {{ $slot }}
        </main>
    </div>
</body>
</html>
