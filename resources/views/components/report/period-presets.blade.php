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
    <span class="mr-1 text-gray-400">Periode cepat</span>
    @foreach ($presets as $label => [$start, $end])
        @php $on = request('from') === $start->format('Y-m-d') && request('to') === $end->format('Y-m-d'); @endphp
        <a href="{{ request()->fullUrlWithQuery(['from' => $start->format('Y-m-d'), 'to' => $end->format('Y-m-d'), 'export' => null]) }}"
           @class(['rounded-full border px-2.5 py-1 font-medium transition', 'border-gray-900 bg-gray-900 text-white' => $on, 'border-gray-200 bg-white text-gray-600 hover:border-gray-300 hover:bg-gray-50 hover:text-gray-900' => ! $on])>{{ $label }}</a>
    @endforeach
</div>
