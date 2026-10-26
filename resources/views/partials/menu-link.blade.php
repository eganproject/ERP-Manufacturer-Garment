@php $active = request()->routeIs($item['active']); @endphp
<a href="{{ route($item['route']) }}" @if ($active) aria-current="page" @endif
   @class([
       'group relative flex items-center gap-3 rounded-lg px-2.5 py-[7px] text-[13.5px] transition-colors',
       'bg-gray-100 font-medium text-gray-900' => $active,
       'text-gray-600 hover:bg-gray-50 hover:text-gray-900' => ! $active,
   ])>
    @if ($active)<span class="absolute top-1.5 bottom-1.5 -left-3 w-[3px] rounded-r-full bg-indigo-600"></span>@endif
    <svg @class(['h-[18px] w-[18px] shrink-0', 'text-indigo-600' => $active, 'text-gray-400 group-hover:text-gray-600' => ! $active]) fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}"/></svg>
    <span class="truncate">{{ $item['label'] }}</span>
</a>
