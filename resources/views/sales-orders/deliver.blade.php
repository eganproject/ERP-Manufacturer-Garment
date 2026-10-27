<x-layouts.app :title="'Kirim Barang - '.$order->number">
    <x-guide title="Mengirim barang ke pelanggan" :steps="['Klik tombol isi otomatis atau ketik jumlah di kolom **Kirim Sekarang**. Jumlah tidak boleh melebihi sisa pesanan maupun stok gudang.', 'Simpan untuk membuat **surat jalan (SJ-)**: stok barang jadi berkurang dan HPP penjualan tercatat.', 'Cetak surat jalan untuk dibawa sopir. Sisa pesanan bisa dikirim berikutnya.']"></x-guide>
    <form method="POST" action="{{ route('sales-orders.deliver.store', $order) }}" class="space-y-6"
          data-confirm="Surat jalan akan dibuat dan stok barang jadi berkurang sesuai jumlah kirim." data-confirm-icon="question" data-confirm-button="Ya, kirim">
        @csrf
        <x-card title="Informasi Surat Jalan">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div>
                    <span class="form-label">Pelanggan</span>
                    <p class="py-2 text-sm font-medium">{{ $order->customer->name }}</p>
                </div>
                <x-form.input name="delivery_date" label="Tanggal Kirim" tip="Tanggal barang keluar dari gudang. Tercatat di surat jalan, kartu stok dan jurnal HPP." :value="now()" required data-date />
                <x-form.warehouse label="Dikirim dari Gudang" tip="Gudang asal barang. Stok dicek dan dikurangi dari gudang ini saja, walau gudang lain masih punya stok." />
                <x-form.input name="vehicle_number" label="No. Kendaraan" tip="Nomor polisi kendaraan pengirim. Dicetak di surat jalan (opsional)." placeholder="D 1234 AB" />
                <x-form.input name="driver_name" label="Nama Sopir / Ekspedisi" />
                <x-form.textarea name="shipping_address" label="Alamat Kirim" tip="Alamat tujuan yang dicetak di surat jalan. Terisi dari alamat pelanggan; ubah bila dikirim ke lokasi lain." :value="$order->customer->address" rows="2" class="md:col-span-2" />
                <x-form.textarea name="notes" label="Catatan" rows="2" class="md:col-span-3" />
            </div>
        </x-card>

        <x-card title="Jumlah Dikirim" :padding="false" x-data>
            <x-slot:header>
                <button type="button" class="btn-secondary btn-sm" @click="document.querySelectorAll('[data-outstanding]').forEach(el => el.value = Math.min(el.dataset.outstanding, el.dataset.stock))">Isi sesuai sisa & stok</button>
            </x-slot:header>
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead><tr><th>Produk</th><th class="text-right">Dipesan</th><th class="text-right">Sudah Dikirim</th><th class="text-right">Sisa</th><th class="text-right">Stok <x-tip title="Stok" text="Stok jual yang tersedia (tidak termasuk stok cacat). Barang hanya bisa dikirim sebanyak stok ini." /></th><th class="w-44">Kirim Sekarang <x-tip title="Kirim sekarang" text="Jumlah pcs yang dikirim pada surat jalan ini. Kosongkan/0 untuk varian yang belum dikirim." /></th></tr></thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($order->items as $item)
                            <tr>
                                <td>{{ $item->variant->display_name }}<div class="text-xs text-gray-500">{{ $item->variant->sku }}</div></td>
                                <td class="text-right">{{ qty($item->quantity) }}</td>
                                <td class="text-right">{{ qty($item->delivered_quantity) }}</td>
                                <td class="text-right font-medium">{{ qty($item->outstandingQuantity()) }}</td>
                                <td @class(['text-right', 'text-red-600 font-medium' => $item->variant->stock < $item->outstandingQuantity()])>{{ qty($item->variant->stock) }}</td>
                                <td>
                                    @if ($item->outstandingQuantity() > 0)
                                        <input type="number" step="1" min="0" max="{{ $item->outstandingQuantity() }}" name="items[{{ $item->id }}]"
                                               value="{{ old('items.'.$item->id) }}" data-outstanding="{{ $item->outstandingQuantity() }}" data-stock="{{ max(0, (float) $item->variant->stock) }}" class="form-control">
                                    @else
                                        <x-badge color="green">Lengkap</x-badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-card>

        <div class="flex justify-end gap-2">
            <a href="{{ route('sales-orders.show', $order) }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-success">Simpan Surat Jalan</button>
        </div>
    </form>
</x-layouts.app>
