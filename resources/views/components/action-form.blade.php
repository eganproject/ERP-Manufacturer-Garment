@props(['action', 'method' => 'POST', 'confirm' => null, 'icon' => 'warning', 'button' => 'Ya, lanjutkan'])

<form method="POST" action="{{ $action }}" class="inline"
      @if ($confirm) data-confirm="{{ $confirm }}" data-confirm-icon="{{ $icon }}" data-confirm-button="{{ $button }}" @endif>
    @csrf
    @if (strtoupper($method) !== 'POST')
        @method($method)
    @endif
    <button type="submit" {{ $attributes->has('class') ? $attributes : $attributes->merge(['class' => 'btn-secondary']) }}>{{ $slot }}</button>
</form>
