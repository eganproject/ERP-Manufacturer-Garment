@props(['name', 'label' => null, 'options' => [], 'value' => null, 'required' => false, 'placeholder' => '-- Pilih --', 'help' => null])

@php
    $key = str_replace(['[', ']'], ['.', ''], $name);
    $id = $attributes->get('id', 'f_'.str_replace('.', '_', $key));
    $current = old($key, $value instanceof \BackedEnum ? $value->value : $value);
@endphp

<div {{ $attributes->only('class') }}>
    @if ($label)
        <label for="{{ $id }}" class="form-label">{{ $label }} @if ($required)<span class="text-red-500">*</span>@endif</label>
    @endif
    <select id="{{ $id }}" name="{{ $name }}" @if ($required) required @endif
            {{ $attributes->except('class')->class(['form-control', 'is-invalid' => $errors->has($key)]) }}>
        @if ($placeholder !== false)
            <option value="">{{ $placeholder }}</option>
        @endif
        @foreach ($options as $optValue => $optLabel)
            <option value="{{ $optValue }}" @selected((string) $current === (string) $optValue)>{{ $optLabel }}</option>
        @endforeach
        {{ $slot }}
    </select>
    @if ($help)
        <p class="mt-1 text-xs text-gray-500">{{ $help }}</p>
    @endif
    @error($key)
        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
    @enderror
</div>
