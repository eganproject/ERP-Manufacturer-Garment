<x-layouts.app title="Penyusutan Aset Tetap">
    <x-slot:actions>
        <a href="{{ route('fixed-assets.index') }}" class="btn-secondary">Daftar Aset</a>
    </x-slot:actions>

    <x-card title="Posting Penyusutan Bulanan" class="mb-6">
        <p class="mb-4 text-sm text-gray-500">
            Penyusutan diposting per bulan (jurnal tanggal akhir bulan): Dr Beban Penyusutan / Cr Akumulasi Penyusutan.
            Bulan yang terlewat ikut disusulkan. Periode akuntansi yang sudah ditutup tidak bisa diposting.
            @if ($last) Terakhir diposting: <strong>{{ $last->translatedFormat('F Y') }}</strong>. @endif
        </p>
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div class="w-48">
                <label class="form-label" for="f_period">Sampai Bulan</label>
                <input type="month" id="f_period" name="period" value="{{ $period->format('Y-m') }}" max="{{ today()->format('Y-m') }}" class="form-control">
            </div>
            <button type="submit" class="btn-secondary">Tampilkan</button>
        </form>

        <div class="mt-4 overflow-x-auto rounded-lg border border-gray-200">
            <table class="table-base">
                <thead><tr><th>Aset</th><th>Kategori</th><th>Bulan</th><th class="text-right">Nilai Buku Sekarang</th><th class="text-right">Penyusutan</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($preview as $row)
                        <tr>
                            <td><a href="{{ route('fixed-assets.show', $row['asset']) }}" class="font-medium text-indigo-600 hover:underline">{{ $row['asset']->number }}</a> {{ $row['asset']->name }}</td>
                            <td class="text-sm">{{ $row['asset']->category->name }}</td>
                            <td class="text-sm">
                                {{ collect($row['lines'])->map(fn ($l) => $l['period']->translatedFormat('M Y'))->implode(', ') }}
                            </td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($row['asset']->bookValue()) }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($row['amount']) }}</td>
                        </tr>
                    @empty
                        <x-empty-row colspan="5" :message="'Tidak ada penyusutan yang perlu diposting s.d. '.$period->translatedFormat('F Y').'.'" />
                    @endforelse
                </tbody>
                @if ($preview->isNotEmpty())
                    <tfoot class="bg-gray-50 font-semibold"><tr><td colspan="4" class="px-4 py-3">Total ({{ $preview->count() }} aset)</td><td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($preview->sum('amount')) }}</td></tr></tfoot>
                @endif
            </table>
        </div>

        @can('assets.manage')
            @if ($preview->isNotEmpty())
                <form method="POST" action="{{ route('depreciation-runs.store') }}" class="mt-4 flex justify-end"
                      data-confirm="Penyusutan s.d. {{ $period->translatedFormat('F Y') }} akan diposting ke jurnal." data-confirm-icon="question" data-confirm-button="Ya, posting">
                    @csrf
                    <input type="hidden" name="period" value="{{ $period->format('Y-m') }}">
                    <button type="submit" class="btn-primary">Posting Penyusutan {{ $period->translatedFormat('F Y') }}</button>
                </form>
            @endif
        @endcan
    </x-card>

    <x-card title="Riwayat Posting" :padding="false">
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>No.</th><th>Bulan</th><th>Tanggal Jurnal</th><th class="text-right">Aset</th><th class="text-right">Total</th><th>Status</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($runs as $run)
                        <tr>
                            <td><a href="{{ route('depreciation-runs.show', $run) }}" class="font-medium text-indigo-600 hover:underline">{{ $run->number }}</a></td>
                            <td>{{ $run->periodLabel() }}</td>
                            <td class="whitespace-nowrap">{{ tanggal($run->date) }}</td>
                            <td class="text-right">{{ $run->asset_count }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($run->total) }}</td>
                            <td><x-status-badge :status="$run->status" /></td>
                        </tr>
                    @empty
                        <x-empty-row colspan="6" message="Belum ada posting penyusutan." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-4 py-3 empty:hidden">{{ $runs->links() }}</div>
    </x-card>
</x-layouts.app>
