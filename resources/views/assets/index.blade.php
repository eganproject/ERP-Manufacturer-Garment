@use('App\Enums\FixedAssetStatus')
<x-layouts.app title="Aset Tetap">
    <x-slot:actions>
        <x-report.export-buttons />
        <a href="{{ route('depreciation-runs.index') }}" class="btn-secondary">Penyusutan Bulanan</a>
        <a href="{{ route('asset-categories.index') }}" class="btn-secondary">Kategori</a>
        @can('assets.manage')
            <a href="{{ route('fixed-assets.create') }}" class="btn-primary">+ Catat Aset</a>
        @endcan
    </x-slot:actions>

    <div class="mb-4 grid grid-cols-1 gap-4 sm:grid-cols-4">
        @foreach ([['Harga perolehan', $data['totals']['cost']], ['Akumulasi penyusutan', $data['totals']['accumulated']], ['Nilai buku per '.tanggal($to), $data['totals']['book_value']], ['Penyusutan '.tanggal($from).' – '.tanggal($to), $data['totals']['depreciation']]] as [$label, $value])
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-sm text-gray-500">{{ $label }}</p>
                <p class="mt-1 text-xl font-semibold text-gray-900">{{ rupiah($value) }}</p>
            </div>
        @endforeach
    </div>

    <x-card :padding="false">
        <div class="border-b border-gray-100 p-4">
            <x-search-filter placeholder="Cari no. / nama / no. seri / lokasi...">
                <div class="w-40"><x-form.input name="from" :value="$from" data-date /></div>
                <div class="w-40"><x-form.input name="to" :value="$to" data-date /></div>
                <div class="w-56"><x-form.select name="category_id" :options="$categories" :value="request('category_id')" placeholder="Semua kategori" /></div>
                <div class="w-40"><x-form.select name="status" :options="FixedAssetStatus::options()" :value="request('status')" placeholder="Semua status" /></div>
            </x-search-filter>
            <p class="mt-2 text-xs text-gray-500">Nilai per tanggal akhir filter; kolom Penyusutan Periode = penyusutan yang sudah diposting dalam rentang tanggal.</p>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>No. Aset</th><th>Nama</th><th>Kategori</th><th>Perolehan</th><th>Metode</th><th class="text-right">Harga Perolehan</th><th class="text-right">Penyusutan Periode</th><th class="text-right">Akumulasi</th><th class="text-right">Nilai Buku</th><th>Status</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($data['rows'] as $row)
                        <tr @class(['opacity-60' => $row['disposed']])>
                            <td><a href="{{ route('fixed-assets.show', $row['key']) }}" class="font-medium text-indigo-600 hover:underline">{{ $row['number'] }}</a></td>
                            <td>{{ $row['name'] }}@if ($row['location'])<div class="text-xs text-gray-500">{{ $row['location'] }}</div>@endif</td>
                            <td class="text-sm">{{ $row['category'] }}</td>
                            <td class="whitespace-nowrap">{{ tanggal($row['acquisition_date']) }}</td>
                            <td class="text-sm">{{ $row['method'] }}<div class="text-xs text-gray-500">{{ $row['life'] }}</div></td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($row['cost']) }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($row['depreciation']) }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($row['accumulated']) }}</td>
                            <td class="text-right font-medium whitespace-nowrap">{{ rupiah($row['book_value']) }}</td>
                            <td><x-badge :color="$row['disposed'] ? 'gray' : 'green'">{{ $row['status'] }}</x-badge></td>
                        </tr>
                    @empty
                        <x-empty-row colspan="10" message="Belum ada aset tetap. Catat aset baru atau aset lama sebagai saldo awal." />
                    @endforelse
                </tbody>
                @if ($data['rows']->isNotEmpty())
                    <tfoot class="bg-gray-50 font-semibold">
                        <tr>
                            <td colspan="5" class="px-4 py-3">Total ({{ $data['totals']['items'] }} aset aktif)</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($data['totals']['cost']) }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($data['totals']['depreciation']) }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($data['totals']['accumulated']) }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($data['totals']['book_value']) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </x-card>

    @if ($data['categories']->count() > 1)
        <x-card title="Ringkasan per Kategori" :padding="false" class="mt-6">
            <table class="table-base">
                <thead><tr><th>Kategori</th><th class="text-right">Aset Aktif</th><th class="text-right">Harga Perolehan</th><th class="text-right">Penyusutan Periode</th><th class="text-right">Akumulasi</th><th class="text-right">Nilai Buku</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach ($data['categories'] as $c)
                        <tr>
                            <td>{{ $c['name'] }}</td>
                            <td class="text-right">{{ $c['items'] }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($c['cost']) }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($c['depreciation']) }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($c['accumulated']) }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($c['book_value']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </x-card>
    @endif
</x-layouts.app>
