<x-layouts.app title="Log Aktivitas">
    <x-card :padding="false">
        <div class="border-b border-gray-200 p-4">
            <x-search-filter placeholder="Cari aktivitas...">
                <div class="w-48">
                    <x-form.select name="user_id" :options="$users" :value="request('user_id')" placeholder="Semua pengguna" />
                </div>
                <div class="w-36"><x-form.input name="date_from" :value="request('date_from')" placeholder="Dari tanggal" data-date /></div>
                <div class="w-36"><x-form.input name="date_to" :value="request('date_to')" placeholder="Sampai tanggal" data-date /></div>
            </x-search-filter>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>Waktu</th><th>Pengguna</th><th>Dokumen</th><th>Aktivitas</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($logs as $log)
                        <tr>
                            <td class="whitespace-nowrap">{{ $log->created_at->translatedFormat('d M Y H:i') }}</td>
                            <td>{{ $log->user?->name ?? 'Sistem' }}</td>
                            <td>
                                @if ($log->subject_type)
                                    @php $url = $log->subjectUrl(); @endphp
                                    <span class="text-xs text-gray-500">{{ $log->subjectLabel() }}</span>
                                    @if ($url)
                                        <a href="{{ $url }}" class="font-medium text-indigo-600 hover:underline">{{ $log->subject->number ?? $log->subject->name ?? '#'.$log->subject_id }}</a>
                                    @else
                                        <span class="text-gray-400">(dihapus)</span>
                                    @endif
                                @else
                                    -
                                @endif
                            </td>
                            <td>{{ $log->description }}</td>
                        </tr>
                    @empty
                        <x-empty-row colspan="4" message="Belum ada aktivitas." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4">{{ $logs->links() }}</div>
    </x-card>
</x-layouts.app>
