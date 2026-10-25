@props(['sources'])

{{-- Jurnal otomatis dari satu atau beberapa dokumen; hanya untuk pengguna dengan hak akuntansi --}}
@can('accounting.view')
    @php
        $sources = collect(\Illuminate\Support\Arr::wrap($sources))->filter();
        $entries = \App\Models\JournalEntry::with('lines.account')
            ->where(function ($q) use ($sources) {
                foreach ($sources as $source) {
                    $q->orWhere(fn ($q) => $q->where('source_type', $source->getMorphClass())->where('source_id', $source->getKey()));
                }
            })
            ->orderBy('date')->orderBy('id')
            ->get();
    @endphp
    @if ($sources->isNotEmpty())
        <x-card title="Jurnal Akuntansi" :padding="false" {{ $attributes }}>
            @if ($entries->isEmpty())
                <p class="p-5 text-sm text-gray-500">Belum ada jurnal untuk dokumen ini.</p>
            @else
                <div class="divide-y divide-gray-100">
                    @foreach ($entries as $entry)
                        <div @class(['px-5 py-3', 'opacity-60' => $entry->isReversed() || $entry->isReversal()])>
                            <div class="flex flex-wrap items-baseline gap-2 text-sm">
                                <a href="{{ route('journals.show', $entry) }}" class="font-medium text-indigo-600 hover:underline">{{ $entry->number }}</a>
                                <span class="text-gray-500">{{ tanggal($entry->date) }}</span>
                                @if ($entry->isReversed())<x-badge color="red">Dibalik</x-badge>@endif
                                @if ($entry->isReversal())<x-badge color="amber">Pembalik</x-badge>@endif
                                <span class="text-gray-700">{{ $entry->description }}</span>
                            </div>
                            <table class="mt-1 w-full text-sm">
                                @foreach ($entry->lines as $line)
                                    <tr>
                                        <td @class(['py-0.5 text-gray-700', 'pl-8' => (float) $line->credit > 0])>{{ $line->account->code }} · {{ $line->account->name }}</td>
                                        <td class="w-36 py-0.5 text-right whitespace-nowrap">{{ (float) $line->debit ? rupiah($line->debit, false) : '' }}</td>
                                        <td class="w-36 py-0.5 text-right whitespace-nowrap">{{ (float) $line->credit ? rupiah($line->credit, false) : '' }}</td>
                                    </tr>
                                @endforeach
                            </table>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-card>
    @endif
@endcan
