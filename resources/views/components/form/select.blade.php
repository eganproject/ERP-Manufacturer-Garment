@props(['name', 'label' => null, 'options' => [], 'value' => null, 'required' => false, 'placeholder' => 'Pilih...', 'help' => null, 'tip' => null, 'native' => false])

@php
    $key = str_replace(['[', ']'], ['.', ''], $name);
    $id = $attributes->get('id', 'f_'.str_replace('.', '_', $key));
    $current = old($key, $value instanceof \BackedEnum ? $value->value : $value);
    // Semua pilihan memakai Choices.js (bisa dicari); :native="true" untuk select biasa
    $enhance = ! $native && ! $attributes->has('data-select');
@endphp

<div {{ $attributes->only('class') }}>
    @if ($label)
        <x-form.label :for="$id" :label="$label" :required="$required" :tip="$tip" />
    @endif
    <select id="{{ $id }}" name="{{ $name }}" @if ($required) required @endif @if ($enhance) data-select @endif
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
        <p class="form-help">{{ $help }}</p>
    @endif
    @error($key)
        <p class="form-error"><svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>{{ $message }}</p>
    @enderror
</div>
