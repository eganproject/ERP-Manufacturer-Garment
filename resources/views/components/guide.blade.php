@props(['title' => 'Panduan singkat', 'steps' => [], 'key' => null])

{{--
    Panduan alur di atas halaman (bisa dilipat). Status buka/tutup diingat per halaman di browser.
    Contoh: <x-guide title="Alur pembelian" :steps="['Buat PO', 'Kirim ke supplier', 'Terima barang']">Teks pembuka opsional</x-guide>
--}}
@php($storeKey = 'erp.guide.'.($key ?? request()->route()?->getName() ?? 'page'))

<section {{ $attributes->merge(['class' => 'guide']) }}
         x-data="{ open: (() => { try { return localStorage.getItem(@js($storeKey)) !== '0' } catch (e) { return true } })(),
                   toggle() { this.open = ! this.open; try { localStorage.setItem(@js($storeKey), this.open ? '1' : '0') } catch (e) {} } }">
    <button type="button" class="guide-head" @click="toggle()" :aria-expanded="open.toString()">
        <span class="guide-icon" aria-hidden="true">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 18h6m-5 3h4M12 3a6 6 0 00-3.6 10.8c.4.3.6.8.6 1.3V16h6v-.9c0-.5.2-1 .6-1.3A6 6 0 0012 3z"/></svg>
        </span>
        <span class="min-w-0 flex-1 text-left">
            <span class="block text-sm font-semibold text-gray-900">{{ $title }}</span>
            <span class="block text-xs text-gray-500" x-text="open ? 'Klik untuk menyembunyikan' : 'Klik untuk melihat langkah-langkahnya'"></span>
        </span>
        <svg class="h-4 w-4 shrink-0 text-gray-400 transition-transform duration-200" :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
    </button>
    <div x-show="open" x-collapse x-cloak>
        <div class="guide-body">
            @if (trim($slot) !== '')
                <p class="mb-3 text-[13px] leading-relaxed text-gray-600">{{ $slot }}</p>
            @endif
            @if ($steps)
                <ol class="guide-steps">
                    @foreach ($steps as $step)
                        <li><span class="guide-step-no">{{ $loop->iteration }}</span><span>{!! \Illuminate\Support\Str::of(e($step))->replaceMatches('/\*\*(.+?)\*\*/', '<strong>$1</strong>') !!}</span></li>
                    @endforeach
                </ol>
            @endif
        </div>
    </div>
</section>
