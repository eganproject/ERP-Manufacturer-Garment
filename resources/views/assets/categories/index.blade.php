<x-layouts.app title="Kategori Aset Tetap">
    <x-slot:actions>
        <a href="{{ route('fixed-assets.index') }}" class="btn-secondary">Daftar Aset</a>
        @can('assets.manage')
            <a href="{{ route('asset-categories.create') }}" class="btn-primary">+ Tambah Kategori</a>
        @endcan
    </x-slot:actions>

    <x-card :padding="false">
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>Kode</th><th>Nama</th><th>Metode</th><th>Masa Manfaat</th><th class="text-right">Nilai Sisa</th><th>Akun Aset</th><th>Akumulasi / Beban Penyusutan</th><th class="text-right">Aset Aktif</th><th class="text-right">Aksi</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($categories as $category)
                        <tr @class(['opacity-60' => ! $category->is_active])>
                            <td class="font-medium">{{ $category->code }}</td>
                            <td>{{ $category->name }}@unless ($category->is_active) <x-badge>Nonaktif</x-badge>@endunless</td>
                            <td>{{ $category->isDepreciable() ? $category->depreciation_method->label() : '-' }}</td>
                            <td>{{ $category->lifeLabel() }}</td>
                            <td class="text-right">{{ qty($category->residual_rate) }}%</td>
                            <td class="text-sm">{{ $category->assetAccount?->display_name }}</td>
                            <td class="text-sm">
                                @if ($category->isDepreciable())
                                    {{ $category->accumulatedAccount?->display_name ?? '-' }}<div class="text-xs text-gray-500">{{ $category->expenseAccount?->display_name ?? '-' }}</div>
                                @else
                                    <span class="text-gray-400">Tidak disusutkan</span>
                                @endif
                            </td>
                            <td class="text-right">{{ $category->assets_count }}</td>
                            <td class="space-x-3 text-right whitespace-nowrap">
                                @can('assets.manage')
                                    <a href="{{ route('asset-categories.edit', $category) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Ubah</a>
                                    <x-delete-button :action="route('asset-categories.destroy', $category)" />
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <x-empty-row colspan="9" />
                    @endforelse
                </tbody>
            </table>
        </div>
        <p class="border-t border-gray-100 px-5 py-3 text-xs text-gray-500">Metode, masa manfaat, dan akun kategori menjadi nilai awal saat aset dicatat. Mengubah kategori tidak mengubah aset yang sudah tercatat.</p>
    </x-card>
</x-layouts.app>
