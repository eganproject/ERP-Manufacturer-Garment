@php
    $t = $totals;
    $routes = [
        'invoice' => ['invoices.show', 'receivables.view'],
        'sales_return' => ['sales-returns.show', 'sales.view'],
        'bill' => ['bills.show', 'payables.view'],
        'purchase_return' => ['purchase-returns.show', 'purchase.view'],
        'asset' => ['fixed-assets.show', 'assets.view'],
    ];
@endphp
<x-layouts.app title="Laporan PPN">
    <x-card class="mb-6">
        <form method="GET" class="flex flex-wrap items-end gap-2">
            <div class="w-48">
                <label class="form-label" for="f_period">Masa Pajak</label>
                <input type="month" id="f_period" name="period" value="{{ $period->format('Y-m') }}" class="form-control">
            </div>
            <button type="submit" class="btn-secondary">Tampilkan</button>
            <div class="ml-auto"><x-report.export-buttons /></div>
        </form>
        <p class="mt-2 text-xs text-gray-500">
            PPN keluaran dari invoice ber-PPN (dikurangi nota kredit retur penjualan), PPN masukan dari tagihan supplier & aset tetap tunai ber-PPN (dikurangi nota debit retur pembelian), menurut tanggal dokumen.
            Kurang bayar disetor lewat <strong>Kas Keluar</strong> ke akun Hutang PPN setelah masa ditutup.
        </p>
    </x-card>

    <div class="mb-6 grid grid-cols-2 gap-4 xl:grid-cols-4">
        @foreach ([
            ['PPN Keluaran', rupiah($t['output']), 'DPP '.rupiah($t['output_dpp']).($t['missing_output'] ? ' · '.$t['missing_output'].' faktur belum diisi' : ''), $t['missing_output'] ? 'amber' : null],
            ['PPN Masukan', rupiah($t['input']), 'DPP '.rupiah($t['input_dpp']).($t['missing_input'] ? ' · '.$t['missing_input'].' faktur belum diisi' : ''), $t['missing_input'] ? 'amber' : null],
            ['Kompensasi masa lalu', rupiah($t['compensation']), 'Lebih bayar masa sebelumnya', null],
            [$t['net'] >= 0 ? 'Kurang bayar (disetor)' : 'Lebih bayar (kompensasi)', rupiah(abs($t['net'])), $closed ? 'Ditutup '.$closed->number : 'Belum ditutup', $t['net'] > 0 ? 'red' : null],
        ] as [$label, $value, $hint, $color])
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-sm text-gray-500">{{ $label }}</p>
                <p @class(['mt-1 text-xl font-semibold', 'text-gray-900' => ! $color, 'text-red-600' => $color === 'red', 'text-amber-600' => $color === 'amber'])>{{ $value }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
            </div>
        @endforeach
    </div>

    @if ($closed && abs($t['difference']) > 0.005)
        <div class="mb-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
            Ada perubahan dokumen setelah masa ini ditutup: hasil sekarang berbeda {{ rupiah($t['difference']) }} dari yang dilaporkan ({{ rupiah($closed->net) }}).
            Batalkan penutupan lalu tutup ulang (pembetulan) bila perlu.
        </div>
    @endif

    @foreach (['output' => 'PPN Keluaran', 'input' => 'PPN Masukan'] as $section => $sectionTitle)
        <x-card :title="$sectionTitle.' – '.$period->translatedFormat('F Y')" :padding="false" class="mb-6">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>Tanggal</th><th>Dokumen</th><th>No. Faktur Pajak</th><th>{{ $section === 'output' ? 'Pembeli' : 'Penjual' }}</th><th>NPWP</th><th class="text-right">DPP</th><th class="text-right">PPN</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($$section as $row)
                            @php
                                [$route, $ability] = $routes[$row['kind']] ?? [null, 'marketplace.view'];
                                $url = $row['url'] ?? ($route ? route($route, $row['id']) : null);
                            @endphp
                            <tr>
                                <td class="whitespace-nowrap">{{ tanggal($row['date']) }}</td>
                                <td>
                                    @can($ability)<a href="{{ $url }}" class="font-medium text-indigo-600 hover:underline">{{ $row['number'] }}</a>@else<span class="font-medium">{{ $row['number'] }}</span>@endcan
                                    <div class="text-xs text-gray-500">{{ $row['type'] }}</div>
                                </td>
                                <td class="whitespace-nowrap">
                                    @if ($row['tax_invoice_number'])
                                        {{ $row['tax_invoice_number'] }}<div class="text-xs text-gray-500">{{ tanggal($row['tax_invoice_date']) }}</div>
                                    @elseif (in_array($row['kind'], ['invoice', 'bill', 'asset'], true))
                                        <span class="text-amber-600">Belum diisi</span>
                                    @elseif ($row['kind'] === 'marketplace')
                                        <span class="text-gray-400">Digunggung (pembeli eceran)</span>
                                    @else
                                        <span class="text-gray-400">Nota retur</span>
                                    @endif
                                </td>
                                <td>{{ $row['party'] }}</td>
                                <td class="text-sm whitespace-nowrap">{{ $row['npwp'] ?: '-' }}</td>
                                <td @class(['text-right whitespace-nowrap', 'text-red-600' => $row['dpp'] < 0])>{{ rupiah($row['dpp'], false) }}</td>
                                <td @class(['text-right whitespace-nowrap', 'text-red-600' => $row['tax'] < 0])>{{ rupiah($row['tax'], false) }}</td>
                            </tr>
                        @empty
                            <x-empty-row colspan="7" message="Tidak ada transaksi ber-PPN pada masa ini." />
                        @endforelse
                    </tbody>
                    @if ($$section->isNotEmpty())
                        <tfoot class="bg-gray-50 font-semibold">
                            <tr><td colspan="5" class="px-4 py-3">Total ({{ $$section->count() }} dokumen)</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($$section->sum('dpp'), false) }}</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($$section->sum('tax'), false) }}</td></tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </x-card>
    @endforeach

    <x-card title="Penutupan Masa PPN" :padding="false">
        <div class="border-b border-gray-100 p-5 text-sm">
            <p class="text-gray-500">
                Menutup masa membuat jurnal akhir bulan: Dr PPN Keluaran / Cr PPN Masukan; selisihnya ke <strong>Hutang PPN</strong> (kurang bayar) atau <strong>PPN Lebih Bayar</strong> yang otomatis dikompensasikan ke masa berikutnya.
                Masa ditutup berurutan; hanya penutupan terakhir yang bisa dibatalkan.
            </p>
            @can('tax.manage')
                @if (! $closed)
                    @if ($canClose)
                        <p class="mt-3 text-amber-700">{{ $canClose }}</p>
                    @else
                        <form method="POST" action="{{ route('vat-periods.store') }}" class="mt-3 flex flex-wrap items-end gap-2"
                              data-confirm="Masa PPN {{ $period->translatedFormat('F Y') }} akan ditutup: {{ $t['net'] >= 0 ? 'kurang bayar' : 'lebih bayar' }} {{ rupiah(abs($t['net'])) }}." data-confirm-icon="question" data-confirm-button="Ya, tutup masa">
                            @csrf
                            <input type="hidden" name="period" value="{{ $period->format('Y-m') }}">
                            <div class="w-80"><x-form.input name="notes" label="Catatan (mis. no. bukti lapor SPT)" /></div>
                            <button type="submit" class="btn-primary">Tutup Masa {{ $period->translatedFormat('F Y') }}</button>
                        </form>
                    @endif
                @endif
            @endcan
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>No.</th><th>Masa</th><th class="text-right">Keluaran</th><th class="text-right">Masukan</th><th class="text-right">Kompensasi</th><th class="text-right">Kurang / (Lebih) Bayar</th><th>Status</th><th></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($periods as $vat)
                        <tr @class(['opacity-60' => $vat->isCancelled()])>
                            <td class="font-medium">{{ $vat->number }}@if ($vat->notes)<div class="text-xs text-gray-500">{{ $vat->notes }}</div>@endif</td>
                            <td><a href="{{ route('reports.vat', ['period' => $vat->period->format('Y-m')]) }}" class="text-indigo-600 hover:underline">{{ $vat->periodLabel() }}</a></td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($vat->output_tax, false) }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($vat->input_tax, false) }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($vat->compensation, false) }}</td>
                            <td @class(['text-right whitespace-nowrap font-medium', 'text-red-600' => $vat->net > 0, 'text-emerald-700' => $vat->net < 0])>{{ $vat->net >= 0 ? rupiah($vat->net, false) : '('.rupiah(-$vat->net, false).')' }}</td>
                            <td><x-status-badge :status="$vat->status" />@if ($vat->cancel_reason)<div class="text-xs text-gray-500">{{ $vat->cancel_reason }}</div>@endif</td>
                            <td class="text-right">
                                @can('tax.manage')
                                    @if (! $vat->isCancelled() && $periods->onFirstPage() && $vat->id === $periods->first(fn ($p) => ! $p->isCancelled())?->id)
                                        <x-reason-form :action="route('vat-periods.cancel', $vat)" title="Batalkan penutupan masa PPN?" text="Jurnal penutupan dibalik; masa bisa ditutup ulang." button="Ya, batalkan" class="btn-outline-danger btn-sm">Batalkan</x-reason-form>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <x-empty-row colspan="8" message="Belum ada masa PPN yang ditutup." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-4 py-3 empty:hidden">{{ $periods->links() }}</div>
    </x-card>
</x-layouts.app>
