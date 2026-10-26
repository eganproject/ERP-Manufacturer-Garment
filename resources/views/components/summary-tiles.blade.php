@props(['tiles'])

{{-- $tiles: array of [label, value, color?] --}}
<div @class(['mb-5 grid grid-cols-1 gap-4', 'sm:grid-cols-2 lg:grid-cols-4' => count($tiles) >= 4, 'sm:grid-cols-3' => count($tiles) < 4])>
    @foreach ($tiles as $tile)
        <div class="card relative overflow-hidden p-4">
            <p class="text-xs font-medium text-gray-500">{{ $tile[0] }}</p>
            <p @class(['mt-1.5 text-xl font-semibold tracking-tight tabular-nums', 'text-gray-900' => empty($tile[2]), 'text-red-600' => ($tile[2] ?? null) === 'red', 'text-amber-600' => ($tile[2] ?? null) === 'amber', 'text-emerald-600' => ($tile[2] ?? null) === 'green'])>{{ $tile[1] }}</p>
            @if (! empty($tile[2]))
                <span @class(['absolute inset-y-0 left-0 w-0.5', 'bg-red-500' => $tile[2] === 'red', 'bg-amber-500' => $tile[2] === 'amber', 'bg-emerald-500' => $tile[2] === 'green'])></span>
            @endif
        </div>
    @endforeach
</div>
