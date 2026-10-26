@props(['placeholder' => 'Cari...'])

<form method="GET" class="flex flex-wrap items-end gap-2">
    <div class="relative w-full sm:w-72">
        <svg class="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
        <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ $placeholder }}" class="form-control pl-9">
    </div>
    {{ $slot }}
    <button type="submit" class="btn-secondary">
        <svg class="h-4 w-4 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4h18l-7 8.5V19l-4 2v-8.5L3 4z"/></svg>
        Filter
    </button>
    @if (request()->hasAny(['q', 'status', 'category_id', 'type', 'low_stock']))
        <a href="{{ url()->current() }}" class="btn-ghost">Reset</a>
    @endif
</form>
