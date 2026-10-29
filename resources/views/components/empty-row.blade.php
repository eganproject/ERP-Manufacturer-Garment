@props(['colspan' => 1, 'message' => 'Belum ada data.', 'compact' => false])

{{-- Baris kosong tabel. compact = versi ringkas untuk tabel pendukung di halaman detail dokumen. --}}
<tr class="hover:bg-transparent">
    <td colspan="{{ $colspan }}" @class(['px-4 text-center', 'py-14' => ! $compact, 'py-5' => $compact])>
        @if ($compact)
            <p class="text-sm text-gray-400">{{ $message }}</p>
        @else
            <div class="mx-auto flex max-w-xs flex-col items-center">
                <span class="mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 text-gray-400">
                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M20 13V7a2 2 0 00-2-2H6a2 2 0 00-2 2v6m16 0v4a2 2 0 01-2 2H6a2 2 0 01-2-2v-4m16 0h-4.5l-1 2h-5l-1-2H4"/></svg>
                </span>
                <p class="font-medium text-gray-800">{{ $message }}</p>
            </div>
        @endif
    </td>
</tr>
