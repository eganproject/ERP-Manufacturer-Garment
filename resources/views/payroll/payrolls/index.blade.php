<x-layouts.app title="Penggajian Borongan">
    <x-slot:actions>
        <a href="{{ route('piecework.index') }}" class="btn-secondary">Hasil Kerja Harian</a>
        @can('payroll.manage')
            <a href="{{ route('payrolls.create') }}" class="btn-primary">+ Susun Penggajian</a>
        @endcan
    </x-slot:actions>

    <x-summary-tiles :tiles="[
        ['Hasil kerja belum masuk penggajian', rupiah($unpaid), $unpaid > 0 ? 'amber' : null],
        ['Disetujui, menunggu dibayar', rupiah($pending), $pending > 0 ? 'amber' : null],
        ['Siklus penggajian', \App\Models\Setting::PAYROLL_CYCLES[\App\Models\Setting::payroll()['cycle']]],
    ]" />

    <x-card :padding="false">
        <div class="border-b border-gray-200 p-4">
            <x-search-filter placeholder="Cari no. penggajian...">
                <div class="w-44"><x-form.select name="status" :options="$statuses" :value="request('status')" placeholder="Semua status" /></div>
            </x-search-filter>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>No.</th><th>Periode</th><th class="text-right">Karyawan</th><th class="text-right">Upah</th><th class="text-right">Bonus</th><th class="text-right">Potongan</th><th class="text-right">Dibayar</th><th>Status</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($payrolls as $payroll)
                        <tr>
                            <td><a href="{{ route('payrolls.show', $payroll) }}" class="font-medium text-indigo-600 hover:underline">{{ $payroll->number }}</a></td>
                            <td class="whitespace-nowrap">{{ $payroll->periodLabel() }}</td>
                            <td class="text-right">{{ $payroll->lines_count }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($payroll->total_gross) }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($payroll->total_allowance) }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($payroll->total_deduction) }}</td>
                            <td class="text-right font-semibold whitespace-nowrap">{{ rupiah($payroll->total_net) }}</td>
                            <td><x-status-badge :status="$payroll->status" />@if ($payroll->payment_date)<div class="text-xs text-gray-500">{{ tanggal($payroll->payment_date) }}</div>@endif</td>
                        </tr>
                    @empty
                        <x-empty-row colspan="8" message="Belum ada penggajian. Susun penggajian dari hasil kerja yang belum dibayar." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4">{{ $payrolls->links() }}</div>
    </x-card>
</x-layouts.app>
