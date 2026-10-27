@props(['colspan' => 1, 'message' => 'Belum ada data.', 'compact' => false])

{{-- Baris kosong tabel. compact = versi ringkas untuk tabel pendukung di halaman detail dokumen. --}}
<tr class="hover:bg-transparent">
    <td colspan="{{ $colspan }}" @class(['px-4 text-center', 'py-12' => ! $compact, 'py-5' => $compact])>
        @if ($compact)
            <p class="text-sm text-gray-400">{{ $message }}</p>
        @else
            <div class="mx-auto flex max-w-xs flex-col items-center">
                <span class="mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M20 13V7a2 2 0 00-2-2H6a2 2 0 00-2 2v6m16 0v4a2 2 0 01-2 2H6a2 2 0 01-2-2v-4m16 0h-4.5l-1 2h-5l-1-2H4"/></svg>
                </span>
                <p class="text-sm text-gray-500">{{ $message }}</p>
            </div>
        @endif
    </td>
</tr>
