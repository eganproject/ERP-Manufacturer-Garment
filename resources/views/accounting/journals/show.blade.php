<x-layouts.app :title="'Jurnal '.$entry->number">
    @if ($entry->canReverse())
        @can('accounting.manage')
            <x-slot:actions>
                <a href="{{ route('journals.create', ['copy' => $entry->id]) }}" class="btn-secondary">Salin Jurnal</a>
                <div x-data="{ open: false }" class="relative">
                    <button type="button" class="btn-secondary !text-red-600" @click="open = !open">Batalkan (Jurnal Pembalik)</button>
                    <div x-cloak x-show="open" @click.outside="open = false" class="absolute right-0 z-10 mt-2 w-80 rounded-lg border border-gray-200 bg-white p-4 shadow-lg">
                        <form method="POST" action="{{ route('journals.reverse', $entry) }}" class="space-y-3">
                            @csrf
                            <x-form.input name="date" label="Tanggal jurnal pembalik" :value="today()" required data-date />
                            <x-form.input name="reason" label="Alasan" required />
                            <button type="submit" class="btn-primary w-full">Buat Jurnal Pembalik</button>
                        </form>
                    </div>
                </div>
            </x-slot:actions>
        @endcan
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Informasi Jurnal">
            <dl class="space-y-2 text-sm">
                <div class="flex justify-between gap-4"><dt class="text-gray-500">Nomor</dt><dd class="font-medium">{{ $entry->number }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-gray-500">Tanggal</dt><dd>{{ tanggal($entry->date) }}</dd></div>
                <div class="flex justify-between gap-4"><dt class="text-gray-500">Jenis</dt><dd><x-badge :color="$entry->type->color()">{{ $entry->type->label() }}</x-badge></dd></div>
                @if ($entry->source_number)
                    <div class="flex justify-between gap-4">
                        <dt class="text-gray-500">{{ $entry->sourceLabel() ?? 'Referensi' }}</dt>
                        <dd>
                            @if ($url = $entry->sourceUrl())
                                <a href="{{ $url }}" class="text-indigo-600 hover:underline">{{ $entry->source_number }}</a>
                            @else
                                {{ $entry->source_number }}
                            @endif
                        </dd>
                    </div>
                @endif
                <div class="flex justify-between gap-4"><dt class="text-gray-500">Dibuat oleh</dt><dd>{{ $entry->creator?->name ?? 'Sistem' }}</dd></div>
                @if ($entry->reversalOf)
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Membalik</dt><dd><a href="{{ route('journals.show', $entry->reversalOf) }}" class="text-indigo-600 hover:underline">{{ $entry->reversalOf->number }}</a></dd></div>
                @endif
                @if ($entry->reversal)
                    <div class="flex justify-between gap-4"><dt class="text-gray-500">Dibalik oleh</dt><dd><a href="{{ route('journals.show', $entry->reversal) }}" class="text-indigo-600 hover:underline">{{ $entry->reversal->number }}</a></dd></div>
                @endif
            </dl>
            <p class="mt-4 border-t border-gray-100 pt-3 text-sm text-gray-700">{{ $entry->description }}</p>
        </x-card>

        <x-card title="Rincian Jurnal" :padding="false" class="lg:col-span-2">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>Akun</th><th>Keterangan / Rincian</th><th class="text-right">Debit</th><th class="text-right">Kredit</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($entry->lines as $line)
                            <tr>
                                <td @class(['pl-10' => (float) $line->credit > 0])>
                                    @can('accounting.view')
                                        <a href="{{ route('reports.ledger', ['account_id' => $line->account_id, 'from' => $entry->date->copy()->startOfMonth()->toDateString(), 'to' => $entry->date->copy()->endOfMonth()->toDateString()]) }}" class="text-indigo-600 hover:underline">{{ $line->account->code }} · {{ $line->account->name }}</a>
                                    @endcan
                                </td>
                                <td class="text-sm text-gray-600">{{ $line->description }}@if ($ref = $line->referenceLabel())<div class="text-xs text-gray-500">{{ $ref }}</div>@endif</td>
                                <td class="text-right whitespace-nowrap">{{ (float) $line->debit ? rupiah($line->debit, false) : '' }}</td>
                                <td class="text-right whitespace-nowrap">{{ (float) $line->credit ? rupiah($line->credit, false) : '' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-gray-50 font-semibold">
                        <tr>
                            <td class="px-4 py-3" colspan="2">Total</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($entry->lines->sum('debit'), false) }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($entry->lines->sum('credit'), false) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-card>
    </div>

    @if ($entry->type === \App\Enums\JournalType::Manual)
        <x-activity-log :activities="$entry->activities" class="mt-6" />
    @endif
</x-layouts.app>
