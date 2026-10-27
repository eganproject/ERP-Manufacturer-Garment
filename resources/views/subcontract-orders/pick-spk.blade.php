<x-layouts.app title="Buat Order Maklon" subtitle="Pilih SPK yang tahapnya akan dikerjakan vendor luar">
    <x-guide title="Alur order maklon" :steps="[
        'Pilih **SPK** (Draft atau Dalam Proses) yang sebagian / seluruh tahapnya dikerjakan vendor maklon.',
        'Isi vendor, tahap yang dimaklonkan, qty, tarif jasa, dan bahan yang dikirim dari gudang.',
        'Saat SPK berjalan: **Kirim Bahan** ke vendor, lalu **Terima Hasil** (bagus/reject). Sisa bahan bisa diretur.',
        '**Tutup Order** setelah selesai, lalu Keuangan mencatat tagihan ongkos jasa vendor.',
    ]"></x-guide>

    <x-card title="SPK yang bisa dimaklonkan" :padding="false">
        <div class="overflow-x-auto">
            <table class="table-base">
                <thead><tr><th>No. SPK</th><th>Produk</th><th>SO / Pelanggan</th><th class="text-right">Qty</th><th>Target</th><th>Status</th><th></th></tr></thead>
                <tbody class="divide-y divide-gray-100">
                    @forelse ($spks as $spk)
                        <tr>
                            <td><a href="{{ route('production-orders.show', $spk) }}" class="font-medium text-indigo-600 hover:underline">{{ $spk->number }}</a><div class="text-xs text-gray-500">Mulai {{ tanggal($spk->start_date) }}</div></td>
                            <td>{{ $spk->product->name }}</td>
                            <td>{{ $spk->salesOrder?->number ?? '-' }}<div class="text-xs text-gray-500">{{ $spk->salesOrder?->customer?->name }}</div></td>
                            <td class="text-right">{{ qty($spk->quantity) }}</td>
                            <td>{{ tanggal($spk->due_date) }}</td>
                            <td><x-status-badge :status="$spk->status" /></td>
                            <td class="text-right"><a href="{{ route('subcontract-orders.create', ['production_order_id' => $spk->id]) }}" class="btn-primary btn-sm">Pilih</a></td>
                        </tr>
                    @empty
                        <x-empty-row colspan="7" message="Belum ada SPK Draft / Dalam Proses. Buat SPK dulu, lalu buat order maklon dari SPK tersebut." />
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-card>
</x-layouts.app>
