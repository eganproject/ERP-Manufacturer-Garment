@props(['title' => 'Langkah berikutnya', 'tone' => 'accent'])

{{-- Petunjuk singkat "apa yang harus dilakukan sekarang" di halaman dokumen, mengikuti status dokumen. --}}
@php
    $tones = [
        'accent' => ['box' => 'border-gray-200 bg-white', 'icon' => 'bg-indigo-50 text-indigo-700', 'title' => 'text-gray-900'],
        'amber' => ['box' => 'border-amber-200 bg-amber-50/60', 'icon' => 'bg-amber-100 text-amber-700', 'title' => 'text-amber-900'],
        'green' => ['box' => 'border-emerald-200 bg-emerald-50/60', 'icon' => 'bg-emerald-100 text-emerald-700', 'title' => 'text-emerald-900'],
    ];
    $t = $tones[$tone] ?? $tones['accent'];
@endphp
<div {{ $attributes->merge(['class' => "mb-6 flex items-start gap-3 rounded-xl border px-4 py-3 {$t['box']}"]) }}>
    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full {{ $t['icon'] }}">
        @if ($tone === 'green')
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
        @else
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6"/></svg>
        @endif
    </span>
    <div class="min-w-0 text-sm">
        <p class="font-semibold {{ $t['title'] }}">{{ $title }}</p>
        <p class="mt-0.5 text-gray-600">{{ $slot }}</p>
    </div>
</div>
