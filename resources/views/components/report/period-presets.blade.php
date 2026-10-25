{{-- Pintasan periode laporan --}}
@php
    $presets = [
        'Bulan ini' => [today()->startOfMonth(), today()],
        'Bulan lalu' => [today()->subMonthNoOverflow()->startOfMonth(), today()->subMonthNoOverflow()->endOfMonth()],
        '3 bulan' => [today()->subMonthsNoOverflow(2)->startOfMonth(), today()],
        'Tahun ini' => [today()->startOfYear(), today()],
    ];
@endphp
<div class="flex flex-wrap items-center gap-1 text-xs">
    <span class="text-gray-500">Periode cepat:</span>
    @foreach ($presets as $label => [$start, $end])
        <a href="{{ request()->fullUrlWithQuery(['from' => $start->format('Y-m-d'), 'to' => $end->format('Y-m-d'), 'export' => null]) }}"
           class="rounded-full border border-gray-200 px-2.5 py-0.5 text-gray-600 hover:border-indigo-300 hover:text-indigo-600">{{ $label }}</a>
    @endforeach
</div>
