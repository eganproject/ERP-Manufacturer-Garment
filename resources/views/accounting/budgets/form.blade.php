@php
    $monthNames = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    $initial = old('lines') ? collect(old('lines'))->map(fn ($l) => ['account_id' => $l['account_id'] ?? '', 'months' => array_values(array_pad($l['months'] ?? [], 12, ''))])->values()->all() : $rows;
    if (empty($initial)) {
        $initial = [['account_id' => '', 'months' => array_fill(0, 12, '')]];
    }
@endphp
<x-layouts.app :title="$budget->exists ? 'Ubah Anggaran '.$budget->name : 'Anggaran Baru'">
    @unless ($budget->exists)
        @if ($budgets->isNotEmpty())
            <x-card class="mb-6">
                <form method="GET" action="{{ route('budgets.create') }}" class="flex flex-wrap items-end gap-2">
                    <div class="w-72"><x-form.select name="copy" label="Salin dari anggaran" :options="$budgets->mapWithKeys(fn ($b) => [$b->id => $b->year.' · '.$b->name])" data-select /></div>
                    <div class="w-28"><x-form.input name="year" label="Untuk tahun" type="number" :value="$budget->year" /></div>
                    <div class="w-32"><x-form.input name="growth" label="Naik/turun %" type="number" step="0.1" value="0" /></div>
                    <button type="submit" class="btn-secondary">Salin</button>
                </form>
            </x-card>
        @endif
    @endunless

    <form method="POST" action="{{ $budget->exists ? route('budgets.update', $budget) : route('budgets.store') }}" class="space-y-6"
          x-data="budgetGrid(@js(array_values($initial)))">
        @csrf
        @if ($budget->exists) @method('PUT') @endif

        <x-card title="Informasi Anggaran">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                <x-form.input name="year" label="Tahun" type="number" :value="$budget->year" required />
                <x-form.input name="name" label="Nama Anggaran" :value="$budget->name" required class="md:col-span-1" placeholder="Mis. Anggaran 2027, Revisi 1" />
                <x-form.input name="description" label="Keterangan" :value="$budget->description" class="md:col-span-2" />
            </div>
            <p class="mt-3 text-xs text-gray-500">Isi angka positif menurut sifat akun: pendapatan = target penjualan, beban = batas pengeluaran. Retur/potongan penjualan diisi positif (mengurangi pendapatan). Isi "Setahun" lalu klik ⇉ untuk membagi rata ke 12 bulan.</p>
        </x-card>

        <x-card title="Anggaran per Akun per Bulan" :padding="false">
            <x-slot:header>
                <div class="flex items-center gap-3 text-sm">
                    <span class="text-gray-500">Total pendapatan − beban akan dihitung di laporan</span>
                    <button type="button" class="btn-secondary py-1.5" @click="add()">+ Akun</button>
                </div>
            </x-slot:header>
            <div class="overflow-x-auto">
                <table class="table-base text-sm">
                    <thead>
                        <tr>
                            <th class="sticky left-0 z-10 min-w-72 bg-gray-50">Akun</th>
                            <th class="min-w-44">Setahun</th>
                            @foreach ($monthNames as $m)<th class="min-w-28">{{ $m }}</th>@endforeach
                            <th class="w-10"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <template x-for="(row, index) in rows" :key="row.key">
                            <tr :data-row="row.key">
                                <td class="sticky left-0 z-10 bg-white">
                                    <select :name="`lines[${index}][account_id]`" class="form-control" data-select data-placeholder="Pilih akun..."
                                            x-init="$el.value = row.account_id; $nextTick(() => ERP.initPlugins($el))" @change="row.account_id = $event.target.value">
                                        <option value="">Pilih akun...</option>
                                        @foreach ($accounts as $id => $label)
                                            <option value="{{ $id }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td>
                                    <div class="flex items-center gap-1">
                                        <input type="text" class="form-control" inputmode="numeric" data-money
                                               x-init="$el.value = total(row) || ''; $nextTick(() => ERP.initPlugins($el))" @money-change="row.annual = $event.detail">
                                        <button type="button" class="rounded px-1 text-lg text-indigo-600 hover:bg-indigo-50" title="Bagi rata ke 12 bulan" @click="spread(row, $el.closest('tr'))">⇉</button>
                                    </div>
                                    <div class="mt-0.5 text-right text-xs text-gray-500" x-text="'= ' + ERP.formatNumber(total(row))"></div>
                                </td>
                                <template x-for="(value, m) in row.months" :key="m">
                                    <td>
                                        <input type="text" :name="`lines[${index}][months][${m}]`" class="form-control" inputmode="numeric" data-money data-month
                                               x-init="$el.value = value; $nextTick(() => ERP.initPlugins($el))" @money-change="row.months[m] = $event.detail">
                                    </td>
                                </template>
                                <td class="text-right"><button type="button" class="text-xl text-red-500 hover:text-red-700" @click="remove(index)" x-show="rows.length > 1">&times;</button></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            @error('lines')<p class="px-4 py-3 text-sm text-red-600">{{ $message }}</p>@enderror
            @foreach ($errors->get('lines.*') as $messages)
                @foreach ($messages as $message)<p class="px-4 pb-2 text-sm text-red-600">{{ $message }}</p>@endforeach
            @endforeach
        </x-card>

        <div class="flex justify-between gap-2">
            <div>
                @if ($budget->exists)
                    @can('budget.manage')
                        <button type="submit" form="delete-budget" class="btn-secondary !text-red-600">Hapus Anggaran</button>
                    @endcan
                @endif
            </div>
            <div class="flex gap-2">
                <a href="{{ $budget->exists ? route('budgets.show', $budget) : route('budgets.index') }}" class="btn-secondary">Batal</a>
                <button type="submit" class="btn-primary">Simpan Anggaran</button>
            </div>
        </div>
    </form>

    @if ($budget->exists)
        <form id="delete-budget" method="POST" action="{{ route('budgets.destroy', $budget) }}" data-confirm="Anggaran {{ $budget->name }} akan dihapus permanen." data-confirm-button="Ya, hapus">
            @csrf
            @method('DELETE')
        </form>
    @endif

    <script>
        function budgetGrid(rows) {
            let key = 0;
            const months = () => Array(12).fill('');
            return {
                rows: rows.map(r => ({ account_id: r.account_id ?? '', months: (r.months ?? months()).map(v => (v === 0 || v === '0') ? '' : v), annual: '', key: key++ })),
                add() { this.rows.push({ account_id: '', months: months(), annual: '', key: key++ }); },
                remove(index) { this.rows.splice(index, 1); },
                total(row) { return row.months.reduce((s, v) => s + (Number(v) || 0), 0); },
                spread(row, tr) {
                    const annual = Number(row.annual) || 0;
                    const base = Math.floor(annual / 12);
                    tr.querySelectorAll('[data-month]').forEach((el, i) => {
                        ERP.setMoney(el, i === 11 ? annual - base * 11 : base);
                    });
                },
            };
        }
    </script>
</x-layouts.app>
