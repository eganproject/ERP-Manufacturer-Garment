@props(['cancel' => null, 'submit' => 'Simpan'])

<div class="mt-6 flex flex-wrap items-center justify-end gap-2 border-t border-gray-200 pt-4">
    @if ($cancel)
        <a href="{{ $cancel }}" class="btn-secondary">Batal</a>
    @endif
    <button type="submit" class="btn-primary">{{ $submit }}</button>
</div>
