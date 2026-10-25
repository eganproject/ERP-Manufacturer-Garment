@props(['activities'])

<x-card title="Riwayat Aktivitas" {{ $attributes }}>
    @if ($activities->isEmpty())
        <p class="text-sm text-gray-500">Belum ada aktivitas tercatat.</p>
    @else
        <ol class="relative space-y-4 border-l border-gray-200 pl-5">
            @foreach ($activities as $log)
                <li class="text-sm">
                    <span class="absolute -left-1.5 mt-1.5 h-3 w-3 rounded-full border-2 border-white bg-indigo-400"></span>
                    <p class="text-gray-800">{{ $log->description }}</p>
                    <p class="text-xs text-gray-500">{{ $log->user?->name ?? 'Sistem' }} · {{ $log->created_at->translatedFormat('d M Y H:i') }}</p>
                </li>
            @endforeach
        </ol>
    @endif
</x-card>
