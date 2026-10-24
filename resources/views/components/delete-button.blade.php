@props(['action', 'label' => 'Hapus', 'confirm' => 'Data yang dihapus tidak dapat dikembalikan.'])

<form method="POST" action="{{ $action }}" class="inline" data-confirm="{{ $confirm }}" data-confirm-title="Hapus data?" data-confirm-button="Ya, hapus">
    @csrf
    @method('DELETE')
    <button type="submit" {{ $attributes->merge(['class' => 'text-sm font-medium text-red-600 hover:text-red-800']) }}>{{ $label }}</button>
</form>
