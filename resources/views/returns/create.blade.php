{{-- Form retur penjualan / pembelian. $kind, $order, $orders, $lines --}}
<x-layouts.app :title="'Catat '.$kind->title">
    <x-card :title="'1. Pilih '.$kind->orderLabel" class="mb-6">
        <form method="GET" action="{{ route($kind->route.'.create') }}" class="flex flex-wrap items-end gap-2">
            <div class="w-full sm:w-[28rem]">
                <x-form.select name="order_id" :value="$order?->id" :placeholder="'Pilih '.($kind->isSales ? 'SO yang sudah dikirim' : 'PO yang sudah diterima').'...'" data-select :options="$orders" />
            </div>
            <button type="submit" class="btn-secondary">Tampilkan</button>
        </form>
        @if ($orders->isEmpty())
            <p class="mt-3 text-sm text-gray-500">Belum ada {{ $kind->isSales ? 'SO dengan barang terkirim' : 'PO dengan barang diterima' }} yang dapat diretur.</p>
        @endif
    </x-card>

    @if ($order)
        <form method="POST" action="{{ route($kind->route.'.store') }}" class="space-y-6"
              data-confirm="{{ $kind->isSales ? 'Barang retur akan masuk kembali ke stok barang jadi.' : 'Bahan retur akan dikeluarkan dari stok.' }} Bagian yang sudah ditagih menjadi {{ strtolower($kind->noteLabel) }}." data-confirm-icon="question" data-confirm-button="Ya, simpan retur">
            @csrf
            <input type="hidden" name="order_id" value="{{ $order->id }}">

            <x-card title="2. Informasi Retur">
                <div class="grid grid-cols-1 gap-4 md:grid-cols-4">
                    <div>
                        <span class="form-label">{{ $kind->partyLabel }}</span>
                        <p class="py-2 text-sm font-medium">{{ $order->{$kind->partyRelation}->name }}</p>
                    </div>
                    <x-form.input name="return_date" label="Tanggal Retur" :value="now()" required data-date />
                    <x-form.input name="reason" label="Alasan Retur" required class="md:col-span-2"
                        :placeholder="$kind->isSales ? 'Mis. jahitan lepas, salah ukuran, warna tidak sesuai' : 'Mis. kain cacat, warna tidak sesuai, kelebihan kirim'" />
                    <x-form.textarea name="notes" label="Catatan" rows="2" class="md:col-span-4" />
                </div>
            </x-card>

            <x-card title="3. Barang yang Diretur" :padding="false">
                <div class="overflow-x-auto">
                    <table class="table-base">
                        <thead><tr><th>{{ $kind->itemLabel }}</th><th class="text-right">{{ $kind->shippedLabel }}</th><th class="text-right">Sudah Diretur</th><th class="text-right">Belum Ditagih</th>@unless ($kind->isSales)<th class="text-right">Stok</th>@endunless<th class="text-right">Harga</th><th class="w-40">Retur Sekarang</th></tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach ($lines as $line)
                                <tr>
                                    <td>{{ $line['name'] }}<div class="text-xs text-gray-500">{{ $line['code'] }}</div></td>
                                    <td class="text-right">{{ qty($line['shipped']) }} <span class="text-xs text-gray-500">{{ $line['unit'] }}</span></td>
                                    <td class="text-right">{{ qty($line['returned']) }}</td>
                                    <td class="text-right">{{ qty($line['unbilled']) }}</td>
                                    @unless ($kind->isSales)<td @class(['text-right', 'text-red-600' => $line['stock'] < $line['returnable']])>{{ qty($line['stock']) }}</td>@endunless
                                    <td class="text-right whitespace-nowrap">{{ rupiah($line['price']) }}</td>
                                    <td>
                                        @if ($line['returnable'] > 0)
                                            <input type="number" step="any" min="0" max="{{ $line['returnable'] }}" name="items[{{ $line['id'] }}]"
                                                   value="{{ old('items.'.$line['id']) }}" placeholder="maks {{ qty($line['returnable']) }}" class="form-control">
                                            @error('items.'.$line['id'])<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                        @else
                                            <span class="text-xs text-gray-400">-</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="border-t border-gray-100 px-5 py-3 text-xs text-gray-500">
                    Retur mengurangi barang yang belum ditagih lebih dulu. Kelebihannya menjadi {{ strtolower($kind->noteLabel) }}
                    (harga {{ $kind->isSales ? 'SO setelah diskon' : 'tagihan supplier' }} + PPN) yang otomatis mengurangi {{ strtolower($kind->documentLabel) }} terbuka {{ $kind->isSales ? 'SO' : 'PO' }} ini.
                </p>
                @error('items')<p class="px-5 pb-3 text-xs text-red-600">{{ $message }}</p>@enderror
                @error('stock')<p class="px-5 pb-3 text-xs text-red-600">{{ $message }}</p>@enderror
            </x-card>

            <div class="flex justify-end gap-2">
                <a href="{{ route($kind->orderRoute, $order) }}" class="btn-secondary">Batal</a>
                <button type="submit" class="btn-primary">Simpan Retur</button>
            </div>
        </form>
    @endif
</x-layouts.app>
