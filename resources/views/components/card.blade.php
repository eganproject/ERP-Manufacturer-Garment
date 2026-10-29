@props(['title' => null, 'subtitle' => null, 'padding' => true, 'tip' => null])

<div {{ $attributes->merge(['class' => 'card']) }}>
    @if ($title || isset($header))
        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-2 border-b border-gray-100 px-5 py-3">
            <div class="min-w-0">
                <h2 class="flex items-center gap-1.5 text-base font-semibold text-gray-900">{{ $title }}<x-tip :text="$tip" :title="$title" /></h2>
                @if ($subtitle)<p class="mt-0.5 text-xs text-gray-500">{{ $subtitle }}</p>@endif
            </div>
            {{ $header ?? '' }}
        </div>
    @endif
    <div @class(['p-5' => $padding, 'overflow-x-auto' => ! $padding])>
        {{ $slot }}
    </div>
</div>
