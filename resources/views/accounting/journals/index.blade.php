@use('App\Enums\JournalType')
<x-layouts.app title="Jurnal Umum">
    <x-slot:actions>
        @can('accounting.close')
            <form method="POST" action="{{ route('journals.rebuild') }}" class="inline"
                  data-confirm="Semua jurnal otomatis dihapus lalu dibentuk ulang dari seluruh transaksi yang tersimpan (penerimaan, surat jalan, invoice, tagihan, pembayaran, retur, produksi, maklon, stock opname). Jurnal manual tidak berubah. Hanya bisa bila belum ada periode yang ditutup."
                  data-confirm-icon="question" data-confirm-button="Ya, sinkronkan">
                @csrf
                <button type="submit" class="btn-secondary" @disabled($lockedUntil)>{{ $hasAuto ? 'Bangun Ulang Jurnal Otomatis' : 'Buat Jurnal dari Transaksi Lama' }}</button>
            </form>
        @endcan
        @can('accounting.manage')
            <a href="{{ route('journals.create') }}" class="btn-primary">+ Jurnal Manual</a>
        @endcan
    </x-slot:actions>

    @if ($lockedUntil)
        <div class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">Buku sudah ditutup s.d. <strong>{{ tanggal($lockedUntil) }}</strong>. Transaksi & jurnal baru harus bertanggal setelahnya.</div>
    @endif

    <x-card :padding="false">
        <div class="space-y-2 border-b border-gray-200 p-4">
            <x-search-filter placeholder="Cari no. jurnal / dokumen / keterangan...">
                <div class="w-40"><x-form.input name="from" :value="$from" data-date placeholder="Dari" /></div>
                <div class="w-40"><x-form.input name="to" :value="$to" data-date placeholder="Sampai" /></div>
                <div class="w-36"><x-form.select name="type" :options="JournalType::options()" :value="request('type')" placeholder="Semua jenis" /></div>
                <div class="w-64"><x-form.select name="account_id" :options="$accounts" :value="request('account_id')" placeholder="Semua akun" data-select /></div>
            </x-search-filter>
            <x-report.period-presets />
        </div>

        <div class="divide-y divide-gray-100">
            @forelse ($entries as $entry)
                <div @class(['px-4 py-3', 'opacity-60' => $entry->isReversed() || $entry->isReversal()])>
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <a href="{{ route('journals.show', $entry) }}" class="font-medium text-indigo-600 hover:underline">{{ $entry->number }}</a>
                        <span class="text-sm text-gray-500">{{ tanggal($entry->date) }}</span>
                        <x-badge :color="$entry->type->color()">{{ $entry->type->label() }}</x-badge>
                        @if ($entry->isReversed())<x-badge color="red">Dibalik</x-badge>@endif
                        @if ($entry->isReversal())<x-badge color="amber">Pembalik</x-badge>@endif
                        <span class="flex-1 text-sm text-gray-800">{{ $entry->description }}</span>
                        @if ($entry->source_number)<span class="text-xs text-gray-500">{{ $entry->sourceLabel() ?? 'Ref' }}: {{ $entry->source_number }}</span>@endif
                    </div>
                    <table class="mt-2 w-full text-sm">
                        @foreach ($entry->lines as $line)
                            <tr>
                                <td @class(['py-0.5 text-gray-700', 'pl-8' => (float) $line->credit > 0])>{{ $line->account->code }} · {{ $line->account->name }}@if ($line->description)<span class="text-xs text-gray-400"> — {{ $line->description }}</span>@endif</td>
                                <td class="w-40 py-0.5 text-right whitespace-nowrap">{{ (float) $line->debit ? rupiah($line->debit, false) : '' }}</td>
                                <td class="w-40 py-0.5 text-right whitespace-nowrap">{{ (float) $line->credit ? rupiah($line->credit, false) : '' }}</td>
                            </tr>
                        @endforeach
                    </table>
                </div>
            @empty
                <div class="px-4 py-10 text-center text-sm text-gray-500">
                    Belum ada jurnal pada periode ini.
                    @if (! $hasAuto)
                        <div class="mt-1">Transaksi yang dibuat sebelum modul akuntansi bisa dijurnal dengan tombol <em>Buat Jurnal dari Transaksi Lama</em>.</div>
                    @endif
                </div>
            @endforelse
        </div>
        <div class="border-t border-gray-100 px-4 py-3 empty:hidden">{{ $entries->links() }}</div>
    </x-card>
</x-layouts.app>
