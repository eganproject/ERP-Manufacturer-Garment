<x-layouts.app title="Anggaran">
    @can('budget.manage')
        <x-slot:actions>
            <div x-data="{ open: false }" class="relative">
                <button type="button" class="btn-secondary" @click="open = !open">Isi dari Realisasi…</button>
                <div x-cloak x-show="open" @click.outside="open = false" class="absolute right-0 z-10 mt-2 w-80 rounded-lg border border-gray-200 bg-white p-4 shadow-lg">
                    <form method="GET" action="{{ route('budgets.create') }}" class="space-y-3">
                        <x-form.input name="year" label="Tahun anggaran" type="number" :value="today()->year + (today()->month >= 10 ? 1 : 0)" required />
                        <x-form.input name="actual_year" label="Ambil realisasi laba rugi tahun" type="number" :value="today()->year - (today()->month >= 10 ? 0 : 1)" required />
                        <x-form.input name="growth" label="Kenaikan / penurunan (%)" type="number" step="0.1" value="10" help="Diterapkan ke semua akun; bisa diubah per akun sesudahnya." />
                        <button type="submit" class="btn-primary w-full">Buat Draf Anggaran</button>
                    </form>
                </div>
            </div>
            <a href="{{ route('budgets.create') }}" class="btn-primary">+ Anggaran Baru</a>
        </x-slot:actions>
    @endcan

    <x-card :padding="false">
        <div class="border-b border-gray-200 p-4">
            <form method="GET" class="flex flex-wrap items-end gap-2">
                <div class="w-32"><x-form.input name="year" type="number" :value="request('year')" placeholder="Tahun" /></div>
                <button type="submit" class="btn-secondary">Filter</button>
            </form>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>Tahun</th><th>Nama Anggaran</th><th class="text-right">Akun</th><th class="text-right">Pendapatan</th><th class="text-right">HPP & Beban</th><th class="text-right">Laba Dianggarkan</th><th>Disusun oleh</th><th></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($budgets as $budget)
                        @php $s = $summaries[$budget->id]; @endphp
                        <tr>
                            <td class="font-medium">{{ $budget->year }}</td>
                            <td>
                                <a href="{{ route('budgets.show', $budget) }}" class="font-medium text-indigo-600 hover:underline">{{ $budget->name }}</a>
                                @if ($budget->description)<div class="text-xs text-gray-500">{{ $budget->description }}</div>@endif
                            </td>
                            <td class="text-right">{{ $s['accounts'] }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($s['revenue'], false) }}</td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($s['costs'], false) }}</td>
                            <td @class(['text-right font-medium whitespace-nowrap', 'text-red-600' => $s['profit'] < 0])>{{ rupiah($s['profit'], false) }}</td>
                            <td class="text-sm">{{ $budget->creator?->name ?? '-' }}</td>
                            <td class="text-right whitespace-nowrap">
                                <a href="{{ route('budgets.show', $budget) }}" class="text-sm font-medium text-indigo-600 hover:underline">Realisasi</a>
                                @can('budget.manage')
                                    <a href="{{ route('budgets.edit', $budget) }}" class="ml-3 text-sm text-gray-600 hover:underline">Ubah</a>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <x-empty-row colspan="8" message="Belum ada anggaran. Buat baru atau isi otomatis dari realisasi tahun lalu." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</x-layouts.app>
