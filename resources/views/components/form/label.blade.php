@props(['for' => null, 'label', 'required' => false, 'tip' => null])

{{-- Label field + tanda wajib + ikon penjelasan (opsional) --}}
@if ($tip)
    <div class="form-label-row">
        <label @if ($for) for="{{ $for }}" @endif class="form-label">{{ $label }} @if ($required)<span class="text-red-500">*</span>@endif</label>
        <x-tip :text="$tip" :title="$label" />
    </div>
@else
    <label @if ($for) for="{{ $for }}" @endif class="form-label">{{ $label }} @if ($required)<span class="text-red-500">*</span>@endif</label>
@endif
