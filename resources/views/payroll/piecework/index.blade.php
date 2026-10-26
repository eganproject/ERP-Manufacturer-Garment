<x-layouts.app title="Hasil Kerja Borongan">
    <x-slot:actions>
        <a href="{{ route('reports.labor') }}" class="btn-secondary">Laporan Upah</a>
        @can('piecework.record')
            <a href="{{ route('piecework.create') }}" class="btn-primary">+ Input Hasil Kerja</a>
        @endcan
    </x-slot:actions>

    <x-summary-tiles :tiles="[
        ['Upah belum masuk penggajian', rupiah($summary['unpaid']), $summary['unpaid'] > 0 ? 'amber' : null],
        ['Karyawan menunggu dibayar', $summary['unpaid_employees'].' orang'],
        ['Upah 7 hari terakhir', rupiah($summary['week'])],
    ]" />

    <x-card :padding="false">
        <div class="border-b border-gray-200 p-4">
            <x-search-filter placeholder="Cari no. lembar / SPK...">
                <div class="w-56"><x-form.select name="employee_id" :options="$employees" :value="request('employee_id')" placeholder="Semua karyawan" data-select /></div>
                <div class="w-36"><x-form.input name="from" :value="request('from')" placeholder="Dari" data-date /></div>
                <div class="w-36"><x-form.input name="to" :value="request('to')" placeholder="Sampai" data-date /></div>
                <div class="w-44"><x-form.select name="status" :options="['unpaid' => 'Ada yang belum dibayar']" :value="request('status')" placeholder="Semua" /></div>
            </x-search-filter>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>No. Lembar</th><th>Tanggal</th><th>SPK / Produk</th><th class="text-right">Karyawan</th><th class="text-right">Bagus / Reject</th><th class="text-right">Upah</th><th>Penggajian</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($sheets as $sheet)
                        @php $unpaidCount = $sheet->entries->whereNull('payroll_line_id')->count(); @endphp
                        <tr>
                            <td><a href="{{ route('piecework.show', $sheet) }}" class="font-medium text-indigo-600 hover:underline">{{ $sheet->number }}</a><div class="text-xs text-gray-500">{{ $sheet->creator?->name }}</div></td>
                            <td class="whitespace-nowrap">{{ tanggal($sheet->date) }}</td>
                            <td>{{ $sheet->productionOrder->number }}<div class="text-xs text-gray-500">{{ $sheet->productionOrder->product->name }}</div></td>
                            <td class="text-right">{{ $sheet->entries->pluck('employee_id')->unique()->count() }}</td>
                            <td class="text-right whitespace-nowrap"><span class="text-emerald-700">{{ qty($sheet->entries->sum('quantity')) }}</span> / <span class="text-red-600">{{ qty($sheet->entries->sum('reject_quantity')) }}</span></td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($sheet->total_amount) }}</td>
                            <td>
                                @if ($unpaidCount === 0)
                                    <x-badge color="green">Masuk penggajian</x-badge>
                                @else
                                    <x-badge color="amber">Belum dibayar</x-badge>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <x-empty-row colspan="7" message="Belum ada hasil kerja. Input hasil kerja harian karyawan per SPK." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-4 py-3 empty:hidden">{{ $sheets->links() }}</div>
    </x-card>
</x-layouts.app>
