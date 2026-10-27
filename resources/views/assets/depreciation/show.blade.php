<x-layouts.app :title="'Penyusutan '.$run->periodLabel().' ('.$run->number.')'">
    <x-slot:actions>
        <a href="{{ route('depreciation-runs.index') }}" class="btn-secondary">Penyusutan</a>
        @can('assets.manage')
            @unless ($run->isCancelled())
                <x-reason-form :action="route('depreciation-runs.cancel', $run)" title="Batalkan posting penyusutan?" text="Jurnal dibalik pada tanggal yang sama. Hanya posting terakhir yang bisa dibatalkan." class="!text-red-600">Batalkan</x-reason-form>
            @endunless
        @endcan
    </x-slot:actions>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Informasi">
            <dl class="space-y-3 text-sm">
                <div><dt class="text-gray-500">Status</dt><dd><x-status-badge :status="$run->status" />@if ($run->cancel_reason)<span class="ml-1 text-gray-500">{{ $run->cancel_reason }}</span>@endif</dd></div>
                <div><dt class="text-gray-500">Bulan</dt><dd>{{ $run->periodLabel() }}</dd></div>
                <div><dt class="text-gray-500">Tanggal Jurnal</dt><dd>{{ tanggal($run->date) }}</dd></div>
                <div><dt class="text-gray-500">Jumlah Aset</dt><dd>{{ $run->asset_count }}</dd></div>
                <div><dt class="text-gray-500">Total Penyusutan</dt><dd class="text-lg font-semibold">{{ rupiah($run->total) }}</dd></div>
                <div><dt class="text-gray-500">Diposting oleh</dt><dd>{{ $run->creator?->name ?? '-' }}</dd></div>
            </dl>
        </x-card>

        <div class="space-y-6 lg:col-span-2">
            <x-card title="Rincian per Aset" :padding="false">
                <table class="table-base">
                    <thead><tr><th>Aset</th><th>Bulan</th><th class="text-right">Penyusutan</th><th class="text-right">Akumulasi</th><th class="text-right">Nilai Buku</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($run->lines as $line)
                            <tr>
                                <td><a href="{{ route('fixed-assets.show', $line->asset) }}" class="font-medium text-indigo-600 hover:underline">{{ $line->asset->number }}</a> {{ $line->asset->name }}<div class="text-xs text-gray-500">{{ $line->asset->category->name }}</div></td>
                                <td class="whitespace-nowrap">{{ $line->period->translatedFormat('M Y') }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($line->amount) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($line->accumulated) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($line->book_value) }}</td>
                            </tr>
                        @empty
                            <x-empty-row compact colspan="5" message="Rincian dihapus karena posting dibatalkan." />
                        @endforelse
                    </tbody>
                </table>
            </x-card>
            <x-journal-entries :sources="$run" />
            <x-activity-log :activities="$run->activities" />
        </div>
    </div>
</x-layouts.app>
