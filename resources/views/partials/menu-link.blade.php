@php $active = request()->routeIs($item['active']); @endphp
<a href="{{ route($item['route']) }}"
   @class([
       'flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition',
       'bg-slate-800 font-medium text-white' => $active,
       'hover:bg-slate-800/60 hover:text-white' => ! $active,
   ])>
    <svg class="h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}"/></svg>
    {{ $item['label'] }}
</a>
