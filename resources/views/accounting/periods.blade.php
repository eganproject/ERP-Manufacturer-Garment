<x-layouts.app title="Tutup Buku">
    <div class="mb-4 rounded-lg bg-indigo-50 px-4 py-3 text-sm text-indigo-800">
        Menutup periode mengunci semua transaksi dan jurnal bertanggal di bulan tersebut (dan sebelumnya): invoice, tagihan, pembayaran, penerimaan, pengiriman, produksi, retur, dan jurnal manual tidak bisa lagi dibuat / dibatalkan dengan tanggal itu.
        Tutup buku dilakukan berurutan. Laba rugi tahun buku sebelumnya otomatis menjadi Laba Ditahan di neraca.
    </div>

    <x-card :padding="false">
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>Periode</th><th class="text-right">Pendapatan Bersih</th><th class="text-right">Laba (Rugi) Bersih</th><th>Status</th><th>Ditutup</th><th class="w-80"></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($periods as $i => ['period' => $period, 'revenue' => $revenue, 'net_profit' => $profit])
                        <tr>
                            <td class="font-medium">
                                <a href="{{ route('reports.profit-loss', ['from' => $period->start()->toDateString(), 'to' => $period->end()->toDateString()]) }}" class="text-indigo-600 hover:underline">{{ $period->label() }}</a>
                            </td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($revenue, false) }}</td>
                            <td @class(['text-right whitespace-nowrap font-medium', 'text-red-600' => $profit < 0])>{{ rupiah($profit, false) }}</td>
                            <td>
                                @if ($period->isClosed())
                                    <x-badge color="gray">Ditutup</x-badge>
                                @else
                                    <x-badge color="green">Terbuka</x-badge>
                                @endif
                            </td>
                            <td class="text-sm text-gray-600">
                                @if ($period->isClosed())
                                    {{ $period->closed_at->translatedFormat('d M Y H:i') }}<div class="text-xs text-gray-500">{{ $period->closer?->name }}</div>
                                @endif
                                @if ($period->notes)<div class="text-xs whitespace-pre-line text-gray-500">{{ $period->notes }}</div>@endif
                            </td>
                            <td class="text-right">
                                @if ($period->isClosed())
                                    @php $isLast = ! $periods->slice(0, $i)->contains(fn ($p) => $p['period']->isClosed()); @endphp
                                    @if ($isLast)
                                        <form method="POST" action="{{ route('periods.reopen') }}" class="flex justify-end gap-2" data-confirm="Periode {{ $period->label() }} dibuka kembali sehingga transaksinya bisa diubah." data-confirm-button="Ya, buka">
                                            @csrf
                                            <input type="hidden" name="year" value="{{ $period->year }}">
                                            <input type="hidden" name="month" value="{{ $period->month }}">
                                            <input type="text" name="reason" class="form-control w-44" placeholder="Alasan dibuka" required>
                                            <button type="submit" class="btn-secondary">Buka Kembali</button>
                                        </form>
                                    @endif
                                @else
                                    @php $isNext = $periods->slice($i + 1)->every(fn ($p) => $p['period']->isClosed()); @endphp
                                    @if ($isNext)
                                        <form method="POST" action="{{ route('periods.close') }}" class="flex justify-end gap-2" data-confirm="Tutup buku {{ $period->label() }}? Transaksi s.d. {{ tanggal($period->end()) }} akan terkunci." data-confirm-icon="question" data-confirm-button="Ya, tutup buku">
                                            @csrf
                                            <input type="hidden" name="year" value="{{ $period->year }}">
                                            <input type="hidden" name="month" value="{{ $period->month }}">
                                            <input type="text" name="notes" class="form-control w-44" placeholder="Catatan (opsional)">
                                            <button type="submit" class="btn-primary">Tutup Buku</button>
                                        </form>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-card>
</x-layouts.app>
