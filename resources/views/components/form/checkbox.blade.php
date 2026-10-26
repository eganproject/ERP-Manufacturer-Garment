@props(['name', 'label', 'checked' => false, 'help' => null, 'tip' => null])

<div {{ $attributes->merge(['class' => 'flex items-start gap-1.5']) }}>
    <label class="inline-flex cursor-pointer items-start gap-2.5 text-sm text-gray-700 select-none">
        <input type="hidden" name="{{ $name }}" value="0">
        <input type="checkbox" name="{{ $name }}" value="1" class="mt-0.5"
               @checked(old($name, $checked))>
        <span>
            <span class="font-medium text-gray-800">{{ $label }}</span>
            @if ($help)<span class="block text-xs text-gray-500">{{ $help }}</span>@endif
        </span>
    </label>
    <x-tip :text="$tip" :title="$label" class="mt-0.5" />
</div>
