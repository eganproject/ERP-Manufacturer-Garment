@props(['text' => null, 'title' => null, 'label' => null])

{{--
    Ikon "?" kecil yang menampilkan penjelasan singkat (tooltip).
    Desktop: muncul saat kursor diarahkan / fokus keyboard. HP: ketuk untuk buka, ketuk di luar untuk tutup.
    Isi boleh berisi baris baru (\n) untuk memisahkan poin.
    Contoh: <x-tip title="Gudang" text="Gudang tempat barang masuk." />
--}}
@php($content = str_replace('\n', "\n", trim((string) ($text ?? $slot))))
@if ($content !== '')
    <button type="button" {{ $attributes->class('tip-trigger') }}
            data-tip="{{ $content }}" @if ($title) data-tip-title="{{ $title }}" @endif
            aria-label="Penjelasan{{ ($label ?? $title) ? ': '.($label ?? $title) : '' }}" aria-expanded="false">
        <svg viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="7.25" stroke="currentColor" stroke-width="1.5"/><path d="M8.1 8.05a1.95 1.95 0 113.05 1.6c-.66.45-1.15.86-1.15 1.6v.25" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/><circle cx="10" cy="13.6" r=".9" fill="currentColor"/></svg>
    </button>
@endif
