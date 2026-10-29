<x-layouts.app title="Penawaran Harga">
    @can('sales.manage')
        <x-slot:actions><a href="{{ route('quotations.create') }}" class="btn-primary">+ Buat Penawaran</a></x-slot:actions>
    @endcan

    <div class="mb-6 grid grid-cols-2 gap-4 xl:grid-cols-4">
        @foreach ([
            ['Penawaran aktif', $summary['active'].' dokumen', rupiah($summary['active_value']).' menunggu jawaban'],
            ['Ditawarkan '.tanggal($from).' – '.tanggal($to), rupiah($summary['value']), $summary['count'].' penawaran'],
            ['Diterima (jadi SO)', rupiah($summary['accepted']), 'Periode yang sama'],
            ['Tingkat keberhasilan', $summary['win_rate'] === null ? '-' : number_format($summary['win_rate'], 1, ',', '.').'%', 'Diterima ÷ (diterima + ditolak)'],
        ] as [$label, $value, $hint])
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-sm text-gray-500">{{ $label }}</p>
                <p class="mt-1 text-xl font-semibold text-gray-900">{{ $value }}</p>
                <p class="mt-1 text-xs text-gray-500">{{ $hint }}</p>
            </div>
        @endforeach
    </div>

    <x-card :padding="false">
        <div class="border-b border-gray-100 p-4">
            <x-search-filter placeholder="Cari no. penawaran / perihal / pelanggan...">
                <div class="w-56">
                    <x-form.select name="status" :options="['active' => 'Aktif (menunggu jawaban)', 'expired' => 'Kedaluwarsa'] + \App\Enums\QuotationStatus::options()" :value="request('status')" placeholder="Semua status" />
                </div>
                <div class="w-56">
                    <x-form.select name="customer_id" :options="$customers" :value="request('customer_id')" placeholder="Semua pelanggan" data-select />
                </div>
            </x-search-filter>
        </div>
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>No. Penawaran</th><th>Pelanggan</th><th>Tanggal</th><th>Berlaku s.d.</th><th class="text-right">Total</th><th>Status</th><th>SO</th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($quotations as $quotation)
                        @php [$label, $color] = $quotation->displayStatus(); @endphp
                        <tr>
                            <td><a href="{{ route('quotations.show', $quotation) }}" class="font-medium text-indigo-600 hover:underline">{{ $quotation->number }}</a>
                                @if ($quotation->subject)<div class="text-xs text-gray-500">{{ $quotation->subject }}</div>@endif</td>
                            <td>{{ $quotation->customer->name }}</td>
                            <td class="whitespace-nowrap">{{ tanggal($quotation->quotation_date) }}</td>
                            <td class="whitespace-nowrap">
                                {{ tanggal($quotation->valid_until) }}
                                @if ($quotation->isOpen() && ! $quotation->isExpired() && $quotation->daysLeft() <= 3)<div class="text-xs text-amber-600">{{ $quotation->daysLeft() === 0 ? 'Hari terakhir' : 'Sisa '.$quotation->daysLeft().' hari' }}</div>@endif
                            </td>
                            <td class="text-right whitespace-nowrap">{{ rupiah($quotation->total) }}</td>
                            <td><x-badge :color="$color">{{ $label }}</x-badge></td>
                            <td>@if ($quotation->salesOrder)<a href="{{ route('sales-orders.show', $quotation->salesOrder) }}" class="text-indigo-600 hover:underline">{{ $quotation->salesOrder->number }}</a>@else - @endif</td>
                        </tr>
                    @empty
                        <x-empty-row colspan="7" message="Belum ada penawaran." />
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-gray-100 px-4 py-3 empty:hidden">{{ $quotations->links() }}</div>
    </x-card>
</x-layouts.app>
