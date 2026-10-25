{{-- Daftar retur pada halaman SO / PO. $kind, $returns --}}
<x-card title="Retur Barang" :padding="false" class="lg:col-span-3">
    <div class="overflow-x-auto">
        <table class="table-base">
            <thead><tr><th>No. Retur</th><th>Tanggal</th><th>Alasan</th><th>Item</th><th class="text-right">{{ $kind->noteLabel }}</th><th>Status</th></tr></thead>
            <tbody class="divide-y divide-gray-100">
                @forelse ($returns as $return)
                    @php $status = $return->displayStatus(); @endphp
                    <tr>
                        <td><a href="{{ route($kind->route.'.show', $return) }}" class="font-medium text-indigo-600 hover:underline">{{ $return->number }}</a></td>
                        <td>{{ tanggal($return->return_date) }}</td>
                        <td>{{ $return->reason }}</td>
                        <td>{!! $return->items->map(fn ($i) => e($kind->isSales ? $i->variant->display_name : $i->material->name).': <b>'.qty($i->quantity).'</b>')->implode('<br>') !!}</td>
                        <td class="text-right whitespace-nowrap">{{ rupiah($return->total) }}</td>
                        <td><x-badge :color="$status['color']">{{ $status['label'] }}</x-badge></td>
                    </tr>
                @empty
                    <x-empty-row colspan="6" message="Belum ada retur." />
                @endforelse
            </tbody>
        </table>
    </div>
</x-card>
