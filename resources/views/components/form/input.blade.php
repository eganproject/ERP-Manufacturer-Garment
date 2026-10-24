@props(['name', 'label' => null, 'type' => 'text', 'value' => null, 'required' => false, 'help' => null])

@php
    $key = str_replace(['[', ']'], ['.', ''], $name);
    $id = $attributes->get('id', 'f_'.str_replace('.', '_', $key));
    $current = old($key, $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value);
@endphp

<div {{ $attributes->only('class')->merge(['class' => '']) }}>
    @if ($label)
        <label for="{{ $id }}" class="form-label">{{ $label }} @if ($required)<span class="text-red-500">*</span>@endif</label>
    @endif
    <input type="{{ $type }}" id="{{ $id }}" name="{{ $name }}" value="{{ $current }}"
           @if ($required) required @endif
           {{ $attributes->except('class')->class(['form-control', 'is-invalid' => $errors->has($key)]) }}>
    @if ($help)
        <p class="mt-1 text-xs text-gray-500">{{ $help }}</p>
    @endif
    @error($key)
        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
    @enderror
</div>
