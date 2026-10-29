@props(['color' => 'gray'])

@php
    // Badge pil lembut ala WMS: latar warna-50, teks warna-700, tanpa garis.
    $colors = [
        'gray' => 'bg-gray-100 text-gray-600',
        'blue' => 'bg-indigo-50 text-indigo-700',
        'indigo' => 'bg-white text-gray-700 ring-1 ring-gray-200 ring-inset',
        'green' => 'bg-emerald-50 text-emerald-700',
        'amber' => 'bg-amber-50 text-amber-700',
        'red' => 'bg-red-50 text-red-700',
    ];
@endphp

<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap '.($colors[$color] ?? $colors['gray'])]) }}>{{ $slot }}</span>
