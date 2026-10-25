@php
    use App\Enums\PayrollStatus;
    $user = auth()->user();
    $isDraft = $payroll->isDraft();
    $editable = $isDraft && $user->can('payroll.manage');
@endphp
<x-layouts.app :title="'Penggajian '.$payroll->number">
    <x-slot:actions>
        <a href="{{ route('payrolls.pdf', $payroll) }}" target="_blank" class="btn-secondary">Cetak Rekap</a>
        <a href="{{ route('payrolls.slips', $payroll) }}" target="_blank" class="btn-secondary">Cetak Semua Slip</a>
        @if ($payroll->status !== PayrollStatus::Cancelled && $user->can($isDraft ? 'payroll.manage' : 'payroll.pay'))
            <x-action-form :action="route('payrolls.cancel', $payroll)" button="Ya, batalkan" class="btn-secondary !text-red-600"
                :confirm="$payroll->status === PayrollStatus::Paid ? 'Pembayaran dibatalkan dan jurnalnya dibalik. Hasil kerja kembali berstatus belum dibayar.' : 'Penggajian dibatalkan, hasil kerja kembali berstatus belum dibayar.'">Batalkan</x-action-form>
        @endif
        @if ($editable)
            <x-action-form :action="route('payrolls.recalculate', $payroll)" confirm="Hasil kerja diambil ulang sampai akhir periode; bonus & potongan per karyawan dipertahankan." icon="question" button="Ya, hitung ulang">Hitung Ulang</x-action-form>
        @endif
        @if ($isDraft && $user->can('payroll.pay'))
            <x-action-form :action="route('payrolls.approve', $payroll)" confirm="Penggajian dikunci dan siap dibayar." icon="question" button="Ya, setujui" class="btn-primary">Setujui</x-action-form>
        @endif
    </x-slot:actions>

    <div class="mb-6 grid grid-cols-2 gap-4 xl:grid-cols-5">
        @foreach ([
            ['Status', null],
            ['Periode', $payroll->periodLabel()],
            ['Upah borongan', rupiah($payroll->total_gross)],
            ['Bonus − potongan', rupiah($payroll->total_allowance).' − '.rupiah($payroll->total_deduction)],
            ['Total dibayar', rupiah($payroll->total_net)],
        ] as [$label, $value])
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-sm text-gray-500">{{ $label }}</p>
                @if ($value === null)
                    <p class="mt-1"><x-status-badge :status="$payroll->status" /></p>
                    @if ($payroll->payment_date)<p class="mt-1 text-xs text-gray-500">{{ tanggal($payroll->payment_date) }} · {{ $payroll->payment_method?->label() }}</p>@endif
                @else
                    <p class="mt-1 text-lg font-semibold">{{ $value }}</p>
                @endif
            </div>
        @endforeach
    </div>

    @if ($payroll->status === PayrollStatus::Approved && $user->can('payroll.pay'))
        <x-card title="Bayar Upah" class="mb-6">
            <form method="POST" action="{{ route('payrolls.pay', $payroll) }}" class="flex flex-wrap items-end gap-3" data-confirm="Upah {{ rupiah($payroll->total_net) }} ditandai dibayar dan dijurnal." data-confirm-icon="question" data-confirm-button="Ya, bayar">
                @csrf
                <div class="w-44"><x-form.input name="payment_date" label="Tanggal Bayar" :value="now()->toDateString()" required data-date /></div>
                <div class="w-48"><x-form.select name="payment_method" label="Cara Bayar" :options="$methods" value="cash" :placeholder="false" required /></div>
                <div class="w-64"><x-form.cash-account label="Dari Rekening" /></div>
                <button type="submit" class="btn-success">Tandai Dibayar</button>
            </form>
        </x-card>
    @endif

    <form method="POST" action="{{ route('payrolls.update', $payroll) }}">
        @csrf
        @method('PUT')
        <x-card title="Slip Upah per Karyawan" :padding="false" class="mb-6">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Karyawan</th><th class="text-right">Pcs / Operasi</th><th class="text-right">Upah</th>
                            <th class="text-right">Bonus / Tunjangan</th><th class="text-right">Potong Kasbon</th><th class="text-right">Potongan Lain</th>
                            <th class="text-right">Diterima</th><th>Catatan</th><th></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($payroll->lines as $line)
                            <tr x-data="{ allowance: {{ (float) $line->allowance }}, advance: {{ (float) $line->advance_deduction }}, other: {{ (float) $line->other_deduction }}, gross: {{ (float) $line->gross }} }">
                                <td>
                                    <a href="{{ route('employees.show', $line->employee) }}" class="font-medium text-indigo-600 hover:underline">{{ $line->employee->name }}</a>
                                    <div class="text-xs text-gray-500">{{ $line->employee->code }} · {{ $line->entries->pluck('sheet.date')->map->toDateString()->unique()->count() }} hari kerja</div>
                                </td>
                                <td class="text-right">{{ qty($line->quantity) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($line->gross) }}</td>
                                @if ($editable)
                                    <td class="w-32"><input type="number" min="0" step="1" name="lines[{{ $line->id }}][allowance]" x-model="allowance" class="form-control text-right"></td>
                                    <td class="w-32">
                                        <input type="number" min="0" step="1" max="{{ $balances[$line->id] ?? 0 }}" name="lines[{{ $line->id }}][advance_deduction]" x-model="advance" class="form-control text-right">
                                        <div class="mt-0.5 text-right text-xs text-gray-500">sisa {{ rupiah($balances[$line->id] ?? 0, false) }}</div>
                                    </td>
                                    <td class="w-32"><input type="number" min="0" step="1" name="lines[{{ $line->id }}][other_deduction]" x-model="other" class="form-control text-right"></td>
                                    <td class="text-right font-semibold whitespace-nowrap" x-text="'Rp ' + ERP.formatNumber(gross + (Number(allowance) || 0) - (Number(advance) || 0) - (Number(other) || 0))"></td>
                                    <td class="w-44"><input type="text" name="lines[{{ $line->id }}][notes]" value="{{ $line->notes }}" class="form-control" placeholder="mis. denda telat"></td>
                                @else
                                    <td class="text-right whitespace-nowrap">{{ rupiah($line->allowance) }}</td>
                                    <td class="text-right whitespace-nowrap">{{ rupiah($line->advance_deduction) }}</td>
                                    <td class="text-right whitespace-nowrap">{{ rupiah($line->other_deduction) }}</td>
                                    <td class="text-right font-semibold whitespace-nowrap">{{ rupiah($line->net) }}</td>
                                    <td class="text-xs">{{ $line->notes ?: '-' }}</td>
                                @endif
                                <td class="text-right"><a href="{{ route('payrolls.slip', [$payroll, $line]) }}" target="_blank" class="text-sm text-indigo-600 hover:underline">Slip</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="bg-gray-50 font-semibold">
                        <tr>
                            <td class="px-4 py-3">Total ({{ $payroll->lines->count() }} karyawan)</td>
                            <td class="px-4 py-3 text-right">{{ qty($payroll->lines->sum('quantity')) }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($payroll->total_gross) }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($payroll->total_allowance) }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($payroll->lines->sum('advance_deduction')) }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($payroll->lines->sum('other_deduction')) }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($payroll->total_net) }}</td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            @if ($editable)
                <div class="flex items-center justify-between gap-3 border-t border-gray-200 p-4">
                    <p class="text-xs text-gray-500">Potong kasbon diusulkan otomatis sebesar sisa kasbon (maksimal sebesar upah). Bonus dibebankan ke biaya tunjangan produksi, potongan lain dicatat sebagai pendapatan lain-lain.</p>
                    <button type="submit" class="btn-primary">Simpan Bonus & Potongan</button>
                </div>
            @endif
        </x-card>
    </form>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Informasi">
            <dl class="space-y-3 text-sm">
                <div><dt class="text-gray-500">Disusun</dt><dd>{{ $payroll->creator?->name ?? '-' }} · {{ tanggal($payroll->created_at, 'd M Y H:i') }}</dd></div>
                <div><dt class="text-gray-500">Disetujui</dt><dd>{{ $payroll->approver ? $payroll->approver->name.' · '.tanggal($payroll->approved_at, 'd M Y H:i') : '-' }}</dd></div>
                <div><dt class="text-gray-500">Dibayar oleh</dt><dd>{{ $payroll->payer?->name ?? '-' }}</dd></div>
                @if ($payroll->notes)<div><dt class="text-gray-500">Catatan</dt><dd>{{ $payroll->notes }}</dd></div>@endif
            </dl>
        </x-card>
        <x-journal-entries :sources="$payroll" class="lg:col-span-2" />
        <x-activity-log :activities="$payroll->activities" class="lg:col-span-3" />
    </div>
</x-layouts.app>
