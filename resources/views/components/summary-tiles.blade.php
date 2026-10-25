@props(['tiles'])

{{-- $tiles: array of [label, value, color?] --}}
<div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
    @foreach ($tiles as $tile)
        <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
            <p class="text-sm text-gray-500">{{ $tile[0] }}</p>
            <p @class(['mt-1 text-xl font-semibold', 'text-gray-900' => empty($tile[2]), 'text-red-600' => ($tile[2] ?? null) === 'red', 'text-amber-600' => ($tile[2] ?? null) === 'amber'])>{{ $tile[1] }}</p>
        </div>
    @endforeach
</div>
