@props(['name', 'label' => null, 'value' => null, 'rows' => 3, 'required' => false])

@php
    $key = str_replace(['[', ']'], ['.', ''], $name);
    $id = $attributes->get('id', 'f_'.str_replace('.', '_', $key));
@endphp

<div {{ $attributes->only('class') }}>
    @if ($label)
        <label for="{{ $id }}" class="form-label">{{ $label }} @if ($required)<span class="text-red-500">*</span>@endif</label>
    @endif
    <textarea id="{{ $id }}" name="{{ $name }}" rows="{{ $rows }}" @if ($required) required @endif
              {{ $attributes->except('class')->class(['form-control', 'is-invalid' => $errors->has($key)]) }}>{{ old($key, $value) }}</textarea>
    @error($key)
        <p class="form-error"><svg class="h-3.5 w-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>{{ $message }}</p>
    @enderror
</div>
