@props(['title' => null, 'padding' => true])

<div {{ $attributes->merge(['class' => 'rounded-xl border border-gray-200 bg-white shadow-sm']) }}>
    @if ($title || isset($header))
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-5 py-3">
            <h2 class="text-sm font-semibold text-gray-800">{{ $title }}</h2>
            {{ $header ?? '' }}
        </div>
    @endif
    <div @class(['p-5' => $padding])>
        {{ $slot }}
    </div>
</div>
