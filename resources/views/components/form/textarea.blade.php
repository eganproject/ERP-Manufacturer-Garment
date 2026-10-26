@props(['name', 'label' => null, 'value' => null, 'rows' => 3, 'required' => false, 'help' => null, 'tip' => null])

@php
    $key = str_replace(['[', ']'], ['.', ''], $name);
    $id = $attributes->get('id', 'f_'.str_replace('.', '_', $key));
@endphp

<div {{ $attributes->only('class') }}>
    @if ($label)
        <x-form.label :for="$id" :label="$label" :required="$required" :tip="$tip" />
    @endif
    <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}" @if ($required) required @endif
              {{ $attributes->except('class')->class(['form-control', 'is-invalid' => $errors->has($key)]) }}>{{ old($key, $value) }}</textarea>
    @if ($help && ! $errors->has($key))
        <p class="form-help">{{ $help }}</p>
    @endif
    @error($key)
        <p class="form-error"><svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>{{ $message }}</p>
    @enderror
</div>
