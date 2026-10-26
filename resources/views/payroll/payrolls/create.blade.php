<x-layouts.app title="Susun Penggajian Borongan">
    <x-guide title="Alur penggajian borongan" :steps="['Tentukan periode (default mingguan). Semua hasil kerja yang **belum dibayar** sampai akhir periode otomatis ikut terambil.', 'Setelah disusun, isi bonus, potongan kasbon dan potongan lain per karyawan, lalu simpan.', 'Keuangan **Menyetujui**, lalu **Tandai Dibayar** dengan memilih rekening kas/bank. Slip upah bisa dicetak per karyawan.']"></x-guide>
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
        <x-card title="Periode">
            <form method="POST" action="{{ route('payrolls.store') }}">
                @csrf
                <div class="space-y-4">
                    <x-form.input name="period_start" label="Awal Periode" tip="Tanggal awal minggu/periode gajian. Hanya sebagai keterangan di slip; hasil kerja lama yang belum dibayar tetap ikut." :value="old('period_start', $start->toDateString())" required data-date />
                    <x-form.input name="period_end" label="Akhir Periode" tip="Hasil kerja sampai tanggal ini yang belum dibayar akan dimasukkan ke penggajian." :value="old('period_end', $end->toDateString())" required data-date />
                    <x-form.textarea name="notes" label="Catatan" :value="old('notes')" rows="2" />
                </div>
                <p class="mt-3 text-xs text-gray-500">Semua hasil kerja yang belum dibayar sampai akhir periode ikut dihitung, termasuk input susulan dari periode sebelumnya. Siklus default diatur di Pengaturan Perusahaan.</p>
                <x-form.actions :cancel="route('payrolls.index')" submit="Susun Penggajian" />
            </form>
        </x-card>

        <x-card title="Hasil Kerja Belum Dibayar" :padding="false" class="lg:col-span-2">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>Karyawan</th><th>Tanggal Kerja</th><th class="text-right">Pcs / Operasi</th><th class="text-right">Upah</th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @forelse ($preview as $rows)
                            @php $employee = $rows->first()->employee; $dates = $rows->load('sheet')->pluck('sheet.date'); @endphp
                            <tr>
                                <td class="font-medium">{{ $employee->name }}<div class="text-xs text-gray-500">{{ $employee->code }}</div></td>
                                <td class="text-xs whitespace-nowrap">{{ tanggal($dates->min()) }} – {{ tanggal($dates->max()) }}</td>
                                <td class="text-right">{{ qty($rows->sum('quantity')) }}</td>
                                <td class="text-right whitespace-nowrap">{{ rupiah($rows->sum('amount')) }}</td>
                            </tr>
                        @empty
                            <x-empty-row colspan="4" message="Semua hasil kerja sudah masuk penggajian." />
                        @endforelse
                    </tbody>
                    @if ($preview->isNotEmpty())
                        <tfoot class="bg-gray-50 font-semibold">
                            <tr><td colspan="3" class="px-4 py-3">Total</td><td class="px-4 py-3 text-right whitespace-nowrap">{{ rupiah($preview->flatten()->sum('amount')) }}</td></tr>
                        </tfoot>
                    @endif
                </table>
            </div>
        </x-card>
    </div>
</x-layouts.app>
