@php $active = request()->routeIs($item['active']); @endphp
<a href="{{ route($item['route']) }}" @if ($active) aria-current="page" @endif
   @class([
       'flex h-7 items-center gap-2 rounded-lg px-2 text-[0.8125rem] whitespace-nowrap transition',
       'bg-white font-medium text-gray-900 shadow-sm ring-1 ring-gray-200' => $active,
       'text-gray-700 hover:bg-gray-100' => ! $active,
   ])>
    <svg @class(['h-4 w-4 shrink-0', 'text-indigo-600' => $active, 'text-gray-500' => ! $active]) fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}"/></svg>
    <span class="truncate">{{ $item['label'] }}</span>
</a>
