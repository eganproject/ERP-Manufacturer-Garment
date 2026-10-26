@use('App\Enums\AssetAcquisitionType')
@use('App\Enums\AssetDisposalType')
@use('App\Enums\FixedAssetStatus')
<x-layouts.app :title="'Aset '.$asset->number.' – '.$asset->name">
    <x-slot:actions>
        <a href="{{ route('fixed-assets.index') }}" class="btn-secondary">Daftar Aset</a>
        @can('assets.manage')
            @if ($asset->status !== FixedAssetStatus::Cancelled)
                <a href="{{ route('fixed-assets.edit', $asset) }}" class="btn-secondary">Ubah</a>
            @endif
            @if ($asset->canCancel())
                <x-reason-form :action="route('fixed-assets.cancel', $asset)" title="Batalkan pencatatan aset?" text="Jurnal perolehan dibalik{{ $asset->bill ? ' dan tagihan supplier ikut dibatalkan' : '' }}." class="!text-red-600">Batalkan</x-reason-form>
            @endif
            @if ($asset->status === FixedAssetStatus::Disposed)
                <x-reason-form :action="route('fixed-assets.cancel-disposal', $asset)" title="Batalkan pelepasan aset?" text="Jurnal pelepasan dibalik dan aset kembali aktif.">Batalkan Pelepasan</x-reason-form>
            @endif
        @endcan
    </x-slot:actions>

    @if ($pending && $asset->isActive())
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
            Penyusutan {{ count($pending) }} bulan belum diposting (s.d. {{ end($pending)['period']->translatedFormat('F Y') }}).
            @can('assets.manage')<a href="{{ route('depreciation-runs.index') }}" class="font-medium underline">Posting penyusutan</a>@endcan
        </div>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="space-y-6">
            <x-card title="Informasi Aset">
                <dl class="space-y-3 text-sm">
                    <div><dt class="text-gray-500">Status</dt><dd><x-status-badge :status="$asset->status" />@if ($asset->isFullyDepreciated()) <x-badge color="amber">Sudah habis disusutkan</x-badge>@endif @if ($asset->cancel_reason)<span class="ml-1 text-gray-500">{{ $asset->cancel_reason }}</span>@endif</dd></div>
                    <div><dt class="text-gray-500">Kategori</dt><dd>{{ $asset->category->name }}</dd></div>
                    @if ($asset->serial_number)<div><dt class="text-gray-500">No. Seri / Plat</dt><dd>{{ $asset->serial_number }}</dd></div>@endif
                    @if ($asset->location)<div><dt class="text-gray-500">Lokasi</dt><dd>{{ $asset->location }}</dd></div>@endif
                    <div><dt class="text-gray-500">Perolehan</dt><dd>{{ tanggal($asset->acquisition_date) }} · {{ $asset->acquisition_type->label() }}</dd></div>
                    @if ($asset->supplier)<div><dt class="text-gray-500">Supplier</dt><dd>{{ $asset->supplier->name }}</dd></div>@endif
                    @if ($asset->cashAccount)<div><dt class="text-gray-500">Dibayar dari</dt><dd>{{ $asset->cashAccount->display_name }}</dd></div>@endif
                    @if ($asset->bill)
                        <div><dt class="text-gray-500">Tagihan Supplier</dt><dd>
                            @can('payables.view')<a href="{{ route('bills.show', $asset->bill) }}" class="font-medium text-indigo-600 hover:underline">{{ $asset->bill->number }}</a>@else{{ $asset->bill->number }}@endcan
                            <x-status-badge :status="$asset->bill->status" />
                        </dd></div>
                    @endif
                    @if ($asset->reference)<div><dt class="text-gray-500">No. Faktur / Kuitansi</dt><dd>{{ $asset->reference }}</dd></div>@endif
                    <div><dt class="text-gray-500">Akun</dt><dd class="text-xs">{{ $asset->assetAccount->display_name }}@if ($asset->accumulatedAccount)<br>{{ $asset->accumulatedAccount->display_name }}<br>{{ $asset->expenseAccount?->display_name }}@endif</dd></div>
                    @if ($asset->notes)<div><dt class="text-gray-500">Catatan</dt><dd>{{ $asset->notes }}</dd></div>@endif
                    <div><dt class="text-gray-500">Dicatat oleh</dt><dd>{{ $asset->creator?->name ?? '-' }}</dd></div>
                </dl>
            </x-card>

            <x-card title="Nilai Aset">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-gray-500">Harga perolehan</dt><dd class="font-medium">{{ rupiah($asset->cost) }}</dd></div>
                    @if ((float) $asset->tax_amount > 0)<div class="flex justify-between"><dt class="text-gray-500">PPN masukan</dt><dd>{{ rupiah($asset->tax_amount) }}</dd></div>@endif
                    <div class="flex justify-between"><dt class="text-gray-500">Nilai sisa</dt><dd>{{ rupiah($asset->residual_value) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-gray-500">Metode</dt><dd>{{ $asset->useful_life_months > 0 ? $asset->depreciation_method->label().' · '.$asset->lifeLabel() : 'Tidak disusutkan' }}</dd></div>
                    @if ($asset->useful_life_months > 0)
                        <div class="flex justify-between"><dt class="text-gray-500">Mulai disusutkan</dt><dd>{{ $asset->depreciation_start->translatedFormat('F Y') }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Akhir masa manfaat</dt><dd>{{ $asset->lifeEnd()->translatedFormat('F Y') }}</dd></div>
                    @endif
                    @if ((float) $asset->opening_accumulated > 0)<div class="flex justify-between"><dt class="text-gray-500">Akumulasi saldo awal</dt><dd>{{ rupiah($asset->opening_accumulated) }}</dd></div>@endif
                    <div class="flex justify-between"><dt class="text-gray-500">Akumulasi penyusutan</dt><dd>{{ rupiah($asset->accumulated_depreciation) }}</dd></div>
                    <div class="flex justify-between border-t border-gray-100 pt-2 text-base"><dt class="font-semibold">Nilai buku</dt><dd class="font-semibold">{{ rupiah($asset->bookValue()) }}</dd></div>
                </dl>
            </x-card>

            @if ($asset->status === FixedAssetStatus::Disposed)
                <x-card title="Pelepasan">
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-gray-500">Tanggal</dt><dd>{{ tanggal($asset->disposal_date) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Jenis</dt><dd>{{ $asset->disposal_type->label() }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Hasil penjualan</dt><dd>{{ rupiah($asset->disposal_proceeds) }}</dd></div>
                        @if ($asset->disposalAccount)<div class="flex justify-between"><dt class="text-gray-500">Diterima di</dt><dd>{{ $asset->disposalAccount->name }}</dd></div>@endif
                        <div class="flex justify-between"><dt class="text-gray-500">{{ $asset->disposalGain() >= 0 ? 'Laba' : 'Rugi' }} pelepasan</dt><dd @class(['font-semibold', 'text-emerald-700' => $asset->disposalGain() >= 0, 'text-red-600' => $asset->disposalGain() < 0])>{{ rupiah(abs($asset->disposalGain())) }}</dd></div>
                        @if ($asset->disposal_notes)<div><dt class="text-gray-500">Catatan</dt><dd>{{ $asset->disposal_notes }}</dd></div>@endif
                    </dl>
                </x-card>
            @elseif ($asset->isActive())
                @can('assets.manage')
                    <x-card title="Lepas Aset (Jual / Hapus)" tip="Untuk aset yang dijual, rusak atau hilang. Penyusutan harus sudah diposting sampai bulan sebelum tanggal pelepasan. Selisih harga jual dengan nilai buku dicatat sebagai laba/rugi pelepasan.">
                        <form method="POST" action="{{ route('fixed-assets.dispose', $asset) }}" class="space-y-3" x-data="{ type: 'sold' }"
                              data-confirm="Aset akan dilepas dan laba/rugi pelepasan dijurnal." data-confirm-button="Ya, lepas aset">
                            @csrf
                            <x-form.input name="disposal_date" label="Tanggal" :value="today()" required data-date />
                            <div>
                                <x-form.label label="Jenis" tip="Dijual: ada uang masuk dari pembeli. Dihapus: aset rusak/hilang tanpa penerimaan, sisa nilai buku menjadi rugi." />
                                <select name="disposal_type" class="form-control" x-model="type">
                                    @foreach (AssetDisposalType::options() as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div x-show="type === 'sold'" class="space-y-3">
                                <x-form.input name="disposal_proceeds" label="Harga Jual (Rp)" tip="Uang yang diterima dari pembeli aset." data-money inputmode="numeric" />
                                <x-form.select name="disposal_account_id" label="Diterima di Rekening" tip="Rekening kas/bank tempat uang hasil penjualan aset masuk." :options="$cashAccounts" data-select />
                            </div>
                            <x-form.input name="disposal_notes" label="Catatan" placeholder="Mis. dijual ke pengepul / rusak berat" />
                            <p class="text-xs text-gray-500">Penyusutan harus sudah diposting s.d. bulan sebelum tanggal pelepasan. Nilai buku saat ini {{ rupiah($asset->bookValue()) }}.</p>
                            <button type="submit" class="btn-secondary w-full">Lepas Aset</button>
                        </form>
                    </x-card>
                @endcan
            @endif
        </div>

        <div class="space-y-6 lg:col-span-2">
            @if ($schedule->isNotEmpty())
                <x-card title="Jadwal Penyusutan" :padding="false">
                    <div class="max-h-[32rem] overflow-y-auto">
                        <table class="table-base">
                            <thead class="sticky top-0"><tr><th>Bulan</th><th class="text-right">Penyusutan</th><th class="text-right">Akumulasi</th><th class="text-right">Nilai Buku</th><th>Status</th></tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($schedule as $row)
                                    <tr @class(['bg-emerald-50/40' => $row['posted']])>
                                        <td class="whitespace-nowrap">{{ $row['period']->translatedFormat('M Y') }}</td>
                                        <td class="text-right whitespace-nowrap">{{ rupiah($row['amount']) }}</td>
                                        <td class="text-right whitespace-nowrap">{{ rupiah($row['accumulated']) }}</td>
                                        <td class="text-right whitespace-nowrap">{{ rupiah($row['book_value']) }}</td>
                                        <td>
                                            @if ($row['posted'])
                                                <a href="{{ route('depreciation-runs.show', $row['run']) }}" class="text-xs text-indigo-600 hover:underline">{{ $row['run']->number }}</a>
                                            @else
                                                <span class="text-xs text-gray-400">Proyeksi</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-card>
            @elseif ($asset->useful_life_months <= 0)
                <x-card title="Jadwal Penyusutan"><p class="text-sm text-gray-500">Aset ini tidak disusutkan (mis. tanah).</p></x-card>
            @endif

            <x-journal-entries :sources="array_filter([$asset, $asset->bill])" />
            <x-activity-log :activities="$asset->activities" />
        </div>
    </div>
</x-layouts.app>
