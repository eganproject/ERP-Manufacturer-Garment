@props(['placeholder' => 'Cari...'])

<form method="GET" class="flex flex-wrap items-end gap-2">
    <div class="w-full sm:w-64">
        <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ $placeholder }}" class="form-control">
    </div>
    {{ $slot }}
    <button type="submit" class="btn-secondary">Filter</button>
    @if (request()->hasAny(['q', 'status', 'category_id', 'type', 'low_stock']))
        <a href="{{ url()->current() }}" class="text-sm text-gray-500 hover:text-gray-700">Reset</a>
    @endif
</form>
