<x-layouts.app :title="$employee->name">
    <x-slot:actions>
        <a href="{{ route('reports.labor', ['employee_id' => $employee->id]) }}" class="btn-secondary">Laporan Upah</a>
        @can('employees.manage')
            <a href="{{ route('employees.edit', $employee) }}" class="btn-primary">Ubah Data</a>
        @endcan
    </x-slot:actions>

    <x-summary-tiles :tiles="[
        ['Upah belum masuk penggajian', rupiah($unpaid), $unpaid > 0 ? 'amber' : null],
        ['Sisa kasbon', rupiah($advanceBalance), $advanceBalance > 0 ? 'red' : null],
        ['Pola kerja', $employee->work_type->label()],
    ]" />

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Data Karyawan">
            <dl class="space-y-3 text-sm">
                <div><dt class="text-gray-500">Kode</dt><dd class="font-medium">{{ $employee->code }}</dd></div>
                <div><dt class="text-gray-500">Status</dt><dd><x-badge :color="$employee->is_active ? 'green' : 'gray'">{{ $employee->is_active ? 'Aktif' : 'Nonaktif' }}</x-badge></dd></div>
                <div><dt class="text-gray-500">Telepon</dt><dd>{{ $employee->phone ?: '-' }}</dd></div>
                <div><dt class="text-gray-500">Rekening</dt><dd>{{ $employee->bank_account ?: '-' }}</dd></div>
                <div><dt class="text-gray-500">Tanggal Masuk</dt><dd>{{ tanggal($employee->join_date) }}</dd></div>
                @if ($employee->address)<div><dt class="text-gray-500">Alamat</dt><dd>{{ $employee->address }}</dd></div>@endif
                @if ($employee->notes)<div><dt class="text-gray-500">Catatan</dt><dd>{{ $employee->notes }}</dd></div>@endif
            </dl>
        </x-card>

        <x-card title="Kasbon" :padding="false" class="lg:col-span-2">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>No.</th><th>Tanggal</th><th>Keterangan</th><th class="text-right">Jumlah</th><th>Status</th><th></th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($employee->advances as $advance)
                            <tr>
                                <td class="font-medium">{{ $advance->number }}</td>
                                <td class="whitespace-nowrap">{{ tanggal($advance->date) }}</td>
                                <td>{{ $advance->notes ?: '-' }}<div class="text-xs text-gray-500">{{ $advance->payment_method->label() }}</div></td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($advance->amount) }}</td>
                                <td><x-status-badge :status="$advance->status" /></td>
                                <td class="text-right whitespace-nowrap">
                                    @can('payroll.view')
                                        <a href="{{ route('employee-advances.pdf', $advance) }}" target="_blank" class="mr-3 text-sm font-medium text-indigo-600 hover:underline">Cetak</a>
                                    @endcan
                                    @if ($advance->status === \App\Enums\ReturnStatus::Posted)
                                        @can('payroll.pay')
                                            <x-action-form :action="route('employee-advances.cancel', $advance)" confirm="Kasbon dibatalkan dan jurnalnya dibalik." button="Ya, batalkan" class="text-sm font-medium text-red-600 hover:text-red-800">Batalkan</x-action-form>
                                        @endcan
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <x-empty-row colspan="6" message="Belum ada kasbon. Kasbon otomatis diusulkan dipotong saat penggajian." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            @can('payroll.pay')
                <form method="POST" action="{{ route('employees.advances.store', $employee) }}" class="grid grid-cols-1 gap-3 border-t border-gray-200 p-4 sm:grid-cols-2 lg:grid-cols-6 lg:items-end">
                    @csrf
                    <x-form.input name="date" label="Tanggal" :value="now()->toDateString()" required data-date />
                    <x-form.input name="amount" label="Jumlah (Rp)" required data-money inputmode="numeric" />
                    <x-form.select name="payment_method" label="Dibayar" :options="$methods" value="cash" :placeholder="false" required />
                    <x-form.cash-account label="Dari Rekening" />
                    <x-form.input name="notes" label="Keterangan" placeholder="mis. keperluan sekolah anak" />
                    <button type="submit" class="btn-primary">+ Catat Kasbon</button>
                </form>
            @endcan
        </x-card>

        <x-card title="Riwayat Penggajian" :padding="false" class="lg:col-span-3">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>Penggajian</th><th>Periode</th><th class="text-right">Pcs/Operasi</th><th class="text-right">Upah</th><th class="text-right">Bonus</th><th class="text-right">Potongan</th><th class="text-right">Diterima</th><th>Status</th><th></th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($payrollLines as $line)
                            <tr>
                                <td><a href="{{ route('payrolls.show', $line->payroll) }}" class="font-medium text-indigo-600 hover:underline">{{ $line->payroll->number }}</a></td>
                                <td class="whitespace-nowrap">{{ $line->payroll->periodLabel() }}</td>
                                <td class="text-right">{{ qty($line->quantity) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($line->gross) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($line->allowance) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($line->totalDeduction()) }}</td>
                                <td class="text-right font-semibold whitespace-nowrap">{{ rupiah($line->net) }}</td>
                                <td><x-status-badge :status="$line->payroll->status" /></td>
                                <td class="text-right"><a href="{{ route('payrolls.slip', [$line->payroll, $line]) }}" target="_blank" class="text-sm text-indigo-600 hover:underline">Slip</a></td>
                            </tr>
                        @empty
                            <x-empty-row colspan="9" message="Belum pernah masuk penggajian." />
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-card>

        <x-card title="Hasil Kerja" :padding="false" class="lg:col-span-3">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>Tanggal</th><th>Lembar</th><th>SPK / Produk</th><th>Operasi</th><th class="text-right">Bagus</th><th class="text-right">Reject</th><th class="text-right">Tarif</th><th class="text-right">Upah</th><th>Penggajian</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($entries as $entry)
                            <tr>
                                <td class="whitespace-nowrap">{{ tanggal($entry->sheet->date) }}</td>
                                <td><a href="{{ route('piecework.show', $entry->sheet) }}" class="text-indigo-600 hover:underline">{{ $entry->sheet->number }}</a></td>
                                <td>{{ $entry->sheet->productionOrder->number }}<div class="text-xs text-gray-500">{{ $entry->sheet->productionOrder->product->name }}</div></td>
                                <td>{{ $entry->operation_name }}</td>
                                <td class="text-right">{{ qty($entry->quantity) }}</td>
                                <td @class(['text-right', 'text-red-600' => $entry->reject_quantity > 0])>{{ qty($entry->reject_quantity) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($entry->rate) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($entry->amount) }}</td>
                                <td>
                                    @if ($entry->payrollLine)
                                        <a href="{{ route('payrolls.show', $entry->payrollLine->payroll) }}" class="text-xs text-indigo-600 hover:underline">{{ $entry->payrollLine->payroll->number }}</a>
                                    @else
                                        <x-badge color="amber">Belum dibayar</x-badge>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <x-empty-row colspan="9" message="Belum ada hasil kerja tercatat." />
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-gray-100 px-4 py-3 empty:hidden">{{ $entries->links() }}</div>
        </x-card>

        <x-activity-log :activities="$employee->activities" class="lg:col-span-3" />
    </div>
</x-layouts.app>
