@props(['label' => 'Lainnya'])

{{-- Menu "Lainnya" untuk aksi sekunder (cetak, ubah, batal, hapus) agar baris tombol halaman tetap ringkas.
     Isi dengan elemen ber-class menu-item / menu-item-danger; komponen x-action-form, x-reason-form, x-delete-button bisa dipakai dengan class tersebut. --}}
@if (trim($slot) !== '')
    <div x-data="{ open: false }" class="relative" @keydown.escape.window="open = false" @click.outside="open = false">
        <button type="button" class="btn-secondary" @click="open = !open" :aria-expanded="open" aria-haspopup="menu">
            {{ $label }}
            <svg class="h-4 w-4 text-gray-400 transition-transform" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
        </button>
        <div x-cloak x-show="open" x-transition.origin.top.right class="menu-panel" role="menu" @click="if ($event.target.closest('a,button')) open = false">
            {{ $slot }}
        </div>
    </div>
@endif
