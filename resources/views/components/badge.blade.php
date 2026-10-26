@props(['color' => 'gray'])

@php
    // Badge lembut bertitik: warna hanya sebagai penanda kecil agar tampilan tetap tenang
    $colors = [
        'gray' => ['bg-gray-100 text-gray-700 ring-gray-200', 'bg-gray-400'],
        'blue' => ['bg-indigo-50 text-indigo-700 ring-indigo-200/70', 'bg-indigo-500'],
        'indigo' => ['bg-white text-gray-800 ring-gray-300', 'bg-indigo-600'],
        'green' => ['bg-emerald-50 text-emerald-700 ring-emerald-200/70', 'bg-emerald-500'],
        'amber' => ['bg-amber-50 text-amber-800 ring-amber-200/80', 'bg-amber-500'],
        'red' => ['bg-red-50 text-red-700 ring-red-200/70', 'bg-red-500'],
    ];
    [$tone, $dot] = $colors[$color] ?? $colors['gray'];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset '.$tone]) }}>
    <span class="h-1.5 w-1.5 shrink-0 rounded-full {{ $dot }}"></span>
    {{ $slot }}
</span>
