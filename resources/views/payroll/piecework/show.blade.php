@php
    $spk = $sheet->productionOrder;
    $canDelete = auth()->user()->can('piecework.record') && $spk->status === \App\Enums\ProductionOrderStatus::InProgress && ! $sheet->isLocked();
@endphp
<x-layouts.app :title="'Hasil Kerja '.$sheet->number">
    <x-slot:actions>
        <a href="{{ route('piecework.pdf', $sheet) }}" target="_blank" class="btn-secondary">Cetak</a>
        @if ($canDelete)
            <x-delete-button :action="route('piecework.destroy', $sheet)" confirm="Lembar hasil kerja dihapus dan upahnya dikeluarkan dari HPP SPK." class="btn-secondary !text-red-600" />
        @endif
        @can('piecework.record')
            @if ($spk->status === \App\Enums\ProductionOrderStatus::InProgress)
                <a href="{{ route('piecework.create', ['production_order_id' => $spk->id]) }}" class="btn-primary">+ Input Lagi untuk SPK Ini</a>
            @endif
        @endcan
    </x-slot:actions>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Informasi">
            <dl class="space-y-3 text-sm">
                <div><dt class="text-gray-500">Tanggal Kerja</dt><dd class="font-medium">{{ tanggal($sheet->date) }}</dd></div>
                <div><dt class="text-gray-500">SPK</dt><dd>@can('production.view')<a href="{{ route('production-orders.show', $spk) }}" class="font-medium text-indigo-600 hover:underline">{{ $spk->number }}</a>@else<span class="font-medium">{{ $spk->number }}</span>@endcan · {{ $spk->product->name }}</dd></div>
                <div><dt class="text-gray-500">Total Upah</dt><dd class="text-lg font-semibold">{{ rupiah($sheet->total_amount) }}</dd></div>
                <div><dt class="text-gray-500">Dicatat oleh</dt><dd>{{ $sheet->creator?->name ?? '-' }} · {{ tanggal($sheet->created_at, 'd M Y H:i') }}</dd></div>
                @if ($sheet->notes)<div><dt class="text-gray-500">Catatan</dt><dd>{{ $sheet->notes }}</dd></div>@endif
                @if ($sheet->isLocked())<p class="rounded-lg bg-gray-50 p-2 text-xs text-gray-600">Sudah masuk penggajian, tidak dapat dihapus.</p>@endif
            </dl>
        </x-card>

        <x-card title="Rincian per Karyawan" :padding="false" class="lg:col-span-2">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>Karyawan</th><th>Operasi</th><th class="text-right">Bagus</th><th class="text-right">Reject</th><th class="text-right">Tarif</th><th class="text-right">Upah</th><th>Penggajian</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($sheet->entries as $entry)
                            <tr>
                                <td><a href="{{ route('employees.show', $entry->employee) }}" class="font-medium text-indigo-600 hover:underline">{{ $entry->employee->name }}</a><div class="text-xs text-gray-500">{{ $entry->employee->code }}</div></td>
                                <td>{{ $entry->operation_name }}</td>
                                <td class="text-right">{{ qty($entry->quantity) }}</td>
                                <td @class(['text-right', 'text-red-600' => $entry->reject_quantity > 0])>{{ qty($entry->reject_quantity) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($entry->rate) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($entry->amount) }}</td>
                                <td>
                                    @if ($entry->payrollLine)
                                        <a href="{{ route('payrolls.show', $entry->payrollLine->payroll) }}" class="text-xs text-indigo-600 hover:underline">{{ $entry->payrollLine->payroll->number }}</a>
                                    @else
                                        <x-badge color="amber">Belum</x-badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-gray-50 font-semibold">
                        <tr>
                            <td colspan="2" class="px-4 py-3">Total</td>
                            <td class="px-4 py-3 text-right">{{ qty($sheet->entries->sum('quantity')) }}</td>
                            <td class="px-4 py-3 text-right">{{ qty($sheet->entries->sum('reject_quantity')) }}</td>
                            <td></td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($sheet->total_amount) }}</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </x-card>
    </div>
</x-layouts.app>
