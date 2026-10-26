@if ($paginator->hasPages())
    @php
        $btn = 'inline-flex h-8 min-w-8 items-center justify-center rounded-lg px-2 text-sm font-medium transition';
        $idle = $btn.' text-gray-600 hover:bg-gray-100 hover:text-gray-900';
        $off = $btn.' cursor-default text-gray-300';
    @endphp
    <nav role="navigation" aria-label="Navigasi halaman" class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-gray-500">
            Menampilkan <span class="font-medium text-gray-800">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}</span>
            dari <span class="font-medium text-gray-800">{{ number_format($paginator->total(), 0, ',', '.') }}</span> data
        </p>

        <div class="flex items-center gap-1">
            @if ($paginator->onFirstPage())
                <span class="{{ $off }}" aria-disabled="true" aria-label="Sebelumnya">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                </span>
            @else
                <a href="{{ $paginator->previousPageUrl() }}" rel="prev" class="{{ $idle }}" aria-label="Sebelumnya">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                </a>
            @endif

            <span class="hidden items-center gap-1 sm:flex">
                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="{{ $off }}">{{ $element }}</span>
                    @endif
                    @if (is_array($element))
                        @foreach ($element as $page => $url)
                            @if ($page == $paginator->currentPage())
                                <span aria-current="page" class="{{ $btn }} bg-gray-900 text-white">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}" class="{{ $idle }}" aria-label="Halaman {{ $page }}">{{ $page }}</a>
                            @endif
                        @endforeach
                    @endif
                @endforeach
            </span>
            <span class="px-2 text-sm text-gray-500 sm:hidden">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</span>

            @if ($paginator->hasMorePages())
                <a href="{{ $paginator->nextPageUrl() }}" rel="next" class="{{ $idle }}" aria-label="Berikutnya">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </a>
            @else
                <span class="{{ $off }}" aria-disabled="true" aria-label="Berikutnya">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </span>
            @endif
        </div>
    </nav>
@endif
