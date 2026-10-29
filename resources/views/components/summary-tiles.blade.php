@props(['tiles'])

{{-- $tiles: array of [label, value, color?] --}}
<div @class(['mb-5 grid grid-cols-1 gap-4', 'sm:grid-cols-2 lg:grid-cols-4' => count($tiles) >= 4, 'sm:grid-cols-3' => count($tiles) < 4])>
    @foreach ($tiles as $tile)
        <div class="card p-4">
            <p class="text-sm text-gray-500">{{ $tile[0] }}</p>
            <p @class(['mt-0.5 text-xl font-semibold tracking-tight tabular-nums', 'text-gray-900' => empty($tile[2]), 'text-red-600' => ($tile[2] ?? null) === 'red', 'text-amber-600' => ($tile[2] ?? null) === 'amber', 'text-emerald-600' => ($tile[2] ?? null) === 'green'])>{{ $tile[1] }}</p>
        </div>
    @endforeach
</div>
